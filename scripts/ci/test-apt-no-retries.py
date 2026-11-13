#!/usr/bin/env python3
"""Every networked APT path in a workflow job runs with APT retries off.

CVE-2026-109518 (durable-workflow/.github issue 295): vulnerable APT can follow a
redirect on a retried package download and install a swapped package as root.
Writing Acquire::Retries "0" before the first APT-capable step removes that
precondition. shivammathur/setup-php can run apt-get itself, so it counts.
Standard library only: jobs are the two-space keys under the top-level jobs map.
"""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
WORKFLOWS = ROOT / '.github/workflows'
NO_RETRIES = "echo 'Acquire::Retries \"0\";' | sudo tee /etc/apt/apt.conf.d/99-no-retries"
APT = re.compile(r'shivammathur/setup-php@|\bapt(?:-get)?\b[^\n]{0,80}\b(?:update|install|upgrade|dist-upgrade)\b|--with-deps|install-deps')


def jobs(source):
    body = re.split(r'(?m)^jobs:\s*$', source, maxsplit=1)[1]
    body = re.split(r'(?m)^\S', body, maxsplit=1)[0]
    parts = re.split(r'(?m)^  ([A-Za-z0-9_-]+):\s*$', body)
    return dict(zip(parts[1::2], parts[2::2]))


def unprotected(source):
    for name, job in jobs(source).items():
        job = re.sub(r'(?m)^\s*#.*$', '', job)
        first = APT.search(job)
        if first and (NO_RETRIES not in job or job.index(NO_RETRIES) > first.start()):
            yield name


DOCKER_NO_RETRIES = re.compile(r"""^RUN echo 'Acquire::Retries "0";' > /etc/apt/apt.conf.d/99-no-retries$""", re.M)
DOCKER_APT = re.compile(r'^RUN\b[^\n]*\bapt(?:-get)?\b[^\n]{0,80}\b(?:update|install|upgrade|dist-upgrade)\b', re.M)


def unprotected_dockerfile(source):
    """Each build stage (FROM) disables retries before its first networked APT."""
    for stage in re.split(r'(?m)^FROM\b', source)[1:]:
        first = DOCKER_APT.search(stage)
        guard = DOCKER_NO_RETRIES.search(stage)
        if first and (guard is None or guard.start() > first.start()):
            return True
    return False


class AptNoRetriesTest(unittest.TestCase):
    def test_every_dockerfile_disables_retries_before_apt(self):
        found = [str(path.relative_to(ROOT)) for path in sorted(ROOT.rglob('Dockerfile*'))
                 if 'vendor' not in path.parts and 'node_modules' not in path.parts
                 and unprotected_dockerfile(path.read_text())]
        self.assertEqual([], found)

    def test_dockerfile_checker_refuses_a_late_or_missing_line(self):
        guard = "RUN echo 'Acquire::Retries \"0\";' > /etc/apt/apt.conf.d/99-no-retries\n"
        apt = "RUN apt-get update && apt-get install -y unzip\n"
        self.assertFalse(unprotected_dockerfile("FROM php\n" + guard + apt))
        self.assertTrue(unprotected_dockerfile("FROM php\n" + apt + guard))
        self.assertTrue(unprotected_dockerfile("FROM php\n" + apt))
        self.assertTrue(unprotected_dockerfile("FROM a\n" + guard + "FROM b\n" + apt))

    def test_every_apt_capable_job_disables_retries_first(self):
        found = [f'{path.name}:{job}' for path in sorted(WORKFLOWS.glob('*.y*ml'))
                 for job in unprotected(path.read_text())]
        self.assertEqual([], found)

    def test_checker_refuses_a_late_or_missing_line(self):
        line = f"      - run: {NO_RETRIES} >/dev/null\n"
        setup = "      - uses: shivammathur/setup-php@" + "a" * 40 + "\n"
        workflow = "on: push\njobs:\n  {}:\n    steps:\n{}"
        self.assertEqual([], list(unprotected(workflow.format('ok', line + setup))))
        self.assertEqual(['late'], list(unprotected(workflow.format('late', setup + line))))
        self.assertEqual(['none'], list(unprotected(workflow.format('none', "      - run: sudo apt-get update\n"))))
        self.assertEqual(['deps'], list(unprotected(workflow.format('deps', "      - run: npx playwright install --with-deps chromium\n"))))


if __name__ == '__main__':
    unittest.main()
