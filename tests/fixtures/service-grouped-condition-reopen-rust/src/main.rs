use durable_workflow::{
    json, Client, ConditionWaitOptions, Error, ParallelOperation, Value, Worker, WorkflowHandle,
};
use std::time::Duration;

async fn history(endpoint: &str, handle: &WorkflowHandle) -> Value {
    reqwest::Client::new()
        .get(format!(
            "{endpoint}/api/workflows/{}/runs/{}/history",
            handle.workflow_id,
            handle.run_id.as_deref().unwrap()
        ))
        .query(&[("page_size", "1000")])
        .bearer_auth("test-token")
        .header("X-Namespace", "default")
        .header("X-Durable-Workflow-Control-Plane-Version", "2")
        .send()
        .await
        .unwrap()
        .error_for_status()
        .unwrap()
        .json()
        .await
        .unwrap()
}

fn opens(snapshot: &Value) -> usize {
    snapshot["events"]
        .as_array()
        .unwrap()
        .iter()
        .filter(|event| event["event_type"] == "ConditionWaitOpened")
        .count()
}

async fn until_open(
    worker: &Worker,
    endpoint: &str,
    handle: &WorkflowHandle,
    target: usize,
) -> Result<Value, Error> {
    tokio::time::timeout(Duration::from_secs(20), async {
        loop {
            worker.run_once().await?;
            let snapshot = history(endpoint, handle).await;
            if opens(&snapshot) >= target {
                return Ok(snapshot);
            }
            tokio::time::sleep(Duration::from_millis(50)).await;
        }
    })
    .await
    .expect("bounded published reproduction")
}

#[tokio::main]
async fn main() {
    let endpoint = std::env::var("DURABLE_WORKFLOW_SERVER_URL").unwrap();
    let client = Client::builder(&endpoint)
        .token(Some("test-token".to_string()))
        .namespace("default")
        .build()
        .unwrap();
    let mut failed = false;
    for mode in ["condition", "parallel", "selection"] {
        let queue = format!("workflow601-{mode}-{}", durable_workflow::Uuid::new_v4());
        let mut worker = Worker::new(client.clone(), &queue)
            .worker_id(format!("{queue}-worker"))
            .poll_timeout(Duration::from_secs(1));
        worker.register_workflow(
            "tests.published-condition-reopen",
            move |ctx, _| async move {
                let predicate_ctx = ctx.clone();
                let condition = || ConditionWaitOptions::new("two-votes", "sha256:two-votes-v1");
                match mode {
                    "condition" => {
                        ctx.wait_condition(condition(), move || {
                            Ok(predicate_ctx.signals("vote")?.len() >= 2)
                        })
                        .await?;
                    }
                    "parallel" => {
                        ctx.parallel(vec![
                            ParallelOperation::timer(Duration::from_secs(300)),
                            ParallelOperation::group(vec![
                                ParallelOperation::signal("never"),
                                ParallelOperation::condition(condition(), move || {
                                    Ok(predicate_ctx.signals("vote")?.len() >= 2)
                                }),
                            ]),
                        ])
                        .await?;
                    }
                    "selection" => {
                        ctx.select_keyed(vec![
                            ("timer", ParallelOperation::timer(Duration::from_secs(300))),
                            (
                                "votes",
                                ParallelOperation::condition(condition(), move || {
                                    Ok(predicate_ctx.signals("vote")?.len() >= 2)
                                }),
                            ),
                        ])
                        .await?;
                    }
                    _ => unreachable!(),
                }
                Ok(Value::Null)
            },
        );
        worker.register().await.unwrap();
        let handle = client
            .start_workflow(
                "tests.published-condition-reopen",
                &queue,
                &queue,
                json!([]),
            )
            .await
            .unwrap();
        until_open(&worker, &endpoint, &handle, 1).await.unwrap();
        handle
            .signal_selected_run("vote", json!(["first"]))
            .await
            .unwrap();
        let outcome = until_open(&worker, &endpoint, &handle, 2).await;
        let snapshot = history(&endpoint, &handle).await;
        let phases = snapshot["events"]
            .as_array()
            .unwrap()
            .iter()
            .map(|event| json!({"event_type":event["event_type"],"sequence":event["payload"]["sequence"]}))
            .collect::<Vec<_>>();
        let result = match outcome {
            Ok(_) => json!({"outcome":"pass"}),
            Err(Error::Http { status, body }) => {
                failed = true;
                let response: Value = serde_json::from_str(&body).unwrap();
                json!({"outcome":"product-fail","status":status.as_u16(),"reason":response["reason"]})
            }
            Err(error) => {
                failed = true;
                json!({"outcome":"product-fail","error":error.to_string()})
            }
        };
        println!(
            "{}",
            json!({"mode":mode,"result":result,"phases":phases,"opens":opens(&snapshot)})
        );
    }
    if failed {
        std::process::exit(1);
    }
}
