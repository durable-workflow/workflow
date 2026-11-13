# PHP runtime for published upgrade journeys

The published Laravel upgrade job builds one fixed runtime for each PHP minor
in the existing product contract. `scripts/ci/php-container-images.json` pins
the official PHP inputs. The Dockerfile, Composer, trust and Brotli inputs
reuse the reviewed SDK PHP source at
`e6494b86f930c0c196b36a5e94d3b7f5f46c437e`; Workflow owns its additional
PHP minor and the actual Laravel consumers. Qualification does not transfer
between callers.

The Docker context is an allowlist. The pinned central APT guard runs before
networked APT, extension compilation uses two jobs, and the build checks the
effective PHP/Composer CA bundles and Brotli runtime. The setup command checks
the real minor, required modules/functions, production INI, existing CI
defaults and disabled coverage, then runs the actual Composer TLS fixture.
Host Python uses isolated imports.

The `php` and `composer` launchers select the inspected immutable config ID,
run as the caller with two CPUs, 2 GiB and 512 PIDs, and remove their containers
on exit. Only source, private dependency state, selected read-only trust inputs
and the owned temporary SQLite directory are mounted. That directory preserves
the original shared-memory database and its sidecars across PHP invocations.
Host credentials are not forwarded. Composer trust is checked before each
networked upgrade operation using the accepted read-only verifier.

Run the offline launcher controls with:

```sh
python3 -I -S -B tests/ci/test_php_container_launcher.py
```

The hosted caller selects authenticated trust, checks out the exact fixed APT
source, and invokes `scripts/ci/setup-php-container.sh` for its matrix minor.
Local controls may reuse a previously built identical runtime config ID, but
must exercise Workflow's own module/INI, TLS and unchanged upgrade journey.
The source recipe, actual caller, closed central admission record and installed
results all require review before the release matrix is qualified. The initial
published timeout-cleanup probe retains its separately qualified PHP setup.
