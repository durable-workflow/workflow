# Public Boundary Check

The public-boundary job protects two separate surfaces.

## Source Tree

The checked-out tree is always scanned in full for private workspace
paths, private tracker handoff labels, and legacy internal organization
references. This scan does not use a commit range because a leaked string
in any shipped source file is still public-facing, regardless of when it
entered history.

## Commit Metadata

Commit author, email, subject, and body are scanned for private handoff
metadata only across commits introduced by the candidate branch.

For pull requests the workflow passes:

- `PUBLIC_BOUNDARY_GIT_RANGE`, using the event base and head commits.
- `PUBLIC_BOUNDARY_GIT_BASELINE`, using the current public target branch
  when that ref is available.

The script evaluates `git rev-list <range> --not <baseline>` before
checking metadata. That preserves rejection for any new candidate commit
with private handoff wording, while already-public target history does not
block unrelated future branches solely because it is reachable from their
base.

For push checks, the workflow scans the pushed range from the previous
public ref to the new ref and does not apply a target-branch baseline.
Release files and package metadata are covered by the source-tree scan;
tag objects and full historical commit metadata are outside this job's
scope.
