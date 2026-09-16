# Contributing to Ultimate Performance

Thank you for your interest in contributing to Ultimate Performance. This
document covers the fork / branch workflow, the test requirement, the
no-unrelated-changes rule, how to report defects, the PR expectations,
and the AI-assisted contribution transparency policy.

## Fork / branch workflow

1. **Fork** the repository on GitHub:
   `https://github.com/alirezax5/Ultimate-Performance`.
2. **Clone** your fork locally:
   ```bash
   git clone https://github.com/<your-username>/Ultimate-Performance.git
   cd Ultimate-Performance
   git remote add upstream https://github.com/alirezax5/Ultimate-Performance.git
   ```
3. **Install** Composer dependencies:
   ```bash
   composer install
   ```
4. **Create a branch** for your change:
   ```bash
   git checkout -b feature/<short-description>
   ```
   Use `feature/` for new features, `fix/` for bug fixes, `docs/` for
   documentation changes, `test/` for new audit suites.
5. **Make your changes.** Follow the [coding conventions](docs/development.md#coding-conventions).
6. **Add or update audit suites** to cover your changes. Every new
   behavior must have a corresponding `tests/audit-<name>.php` that
   emits `[PASS]` / `[FAIL]` / `[SKIP]` / `[BLOCKED]` lines.
7. **Run the full regression** (see below). Keep `0 FAIL`.
8. **Commit** with a clear message. Reference any defect IDs
   (`BENCH-D7`, `HARDEN-1`, etc.) and the audit suite that verifies
   them.
9. **Push** to your fork:
   ```bash
   git push origin feature/<short-description>
   ```
10. **Open a Pull Request** against `main` on the upstream repository.
    Describe what changed, why, and how it was validated.

## Tests required

Every PR must include a passing regression run. The full harness:

```bash
bash tests/run-all-regression.sh R
```

The runner emits one summary line per suite. The 0.6.1 baseline is
**48 suites / 1334 PASS / 0 FAIL / 15 honest skips**. Your PR must
preserve this — no new failures, and no silent skips where there were
passes before.

If your change requires a real daemon (MariaDB, Redis, Apache, Nginx,
RabbitMQ, OLS), run the corresponding `tests/run-*-live.sh` runner.
Live runners self-provision rootless daemons; you do not need root.

### Honest skips

If a suite cannot run in your environment (e.g. RabbitMQ without
credentials), it self-gates into a clean BLOCKED / SKIP state. This is
expected and is **not** counted as a failure. Never fake a PASS — this
is a release-blocking honesty contract.

### Fresh-checkout validation

Before merging, the maintainer will clone your fork at the merge
commit and run `bash tests/run-all-regression.sh R`. The result must
match your reported numbers exactly. Hidden environment dependencies
in your working tree will be caught here.

## No unrelated changes

Keep your PR focused. A PR that mixes a bug fix with a refactoring,
or a feature with a dependency bump, is harder to review and slower
to merge.

- One logical change per PR.
- If you find an unrelated issue while working, open a separate PR for
  it.
- Do not reformat code you did not touch. If you believe the codebase
  needs reformatting, open an issue first and discuss it.
- Do not bump dependencies in a feature PR. Open a separate PR for
  dependency updates.

## How to report defects

Open a GitHub Issue at
`https://github.com/alirezax5/Ultimate-Performance/issues`. Include:

1. **Ultimate Performance version:** `wp eval 'echo ULTIMATE_PERFORMANCE_VERSION;'`
   or the `Stable tag` line in `readme.txt`.
2. **WordPress version:** `wp core version`.
3. **PHP version:** `php -v`.
4. **Web server and version:** `curl -I https://example.com/ | grep -i
   server`.
5. **EnvironmentDetector status:** from `Settings → Ultimate Performance`.
6. **Reproduction steps:** exact URL, exact request (`curl -I ...`),
   expected vs. actual behavior.
7. **Self-test result:** from the admin screen.
8. **Relevant logs:** `wp-content/cache/ultimate-performance/stats.jsonl`
   (bounded, no credentials).

For security vulnerabilities, do **not** open a public issue. See
[SECURITY.md](SECURITY.md) for the responsible disclosure policy.

## PR expectations

A good PR includes:

- **A clear description** of what changed and why. Reference any
  defect IDs and the audit suite that verifies the fix.
- **The regression result** from your local run: `<round> | <suite> |
  pass=N | fail=N | skip/blocked=N` summary lines, or a paste of the
  final totals.
- **Tests** for new behavior. If you add a feature, add an audit suite.
  If you fix a bug, add a regression test that fails without the fix.
- **Documentation** updates if the change affects user-facing behavior.
  Update `docs/<name>.md` and `docs/<name>-fa.md` together (English +
  Persian parity).
- **No unrelated changes** (see above).

The reviewer's job is to verify your validation claim, not to redo the
validation. A PR that arrives without a regression run will be sent
back.

## AI-assisted contributions

Ultimate Performance is developed with AI assistance. AI-assisted
contributions are welcome; the contributor is responsible for
validation.

- **Disclose AI assistance** in the PR description. Mention which tool
  was used and which parts of the change it drafted.
- **Run the regression.** AI-assisted code that fails the regression
  does not ship — the contributor must debug and fix it before
  requesting review.
- **Take responsibility for the validation.** Do not rely on the
  reviewer to catch issues that the regression should have caught.
- **Prefer small, focused PRs.** Large AI-generated PRs are harder to
  review and more likely to contain subtle issues.
- **Review the AI's output critically.** AI tools can produce code
  that looks correct but is wrong — verify every claim, especially
  security-relevant ones. Run the audit suites that cover the touched
  code paths.

The release philosophy is explicit:

> No release is qualified only because the generated code looks
> correct.

This applies to AI-assisted and human-written code equally. The
regression suite is the source of truth.

## Coding conventions

See [docs/development.md](docs/development.md#coding-conventions) for
the full conventions. The short version:

- PHP 8.3+ syntax (readonly, typed properties, named arguments).
- WordPress namespacing: `UltimatePerformance\<Subsystem>`.
- Every filesystem op routes through `SafeFs`.
- Fail-closed: if the plugin cannot prove a response is safe for
  caching, BYPASS.
- Tabs for indentation (WordPress coding standard).
- Every non-trivial class has a docblock explaining the design
  contract.

## License

By contributing, you agree that your contributions are licensed under
the [GPL-2.0-or-later](LICENSE) license. The plugin header in
`ultimate-performance.php` declares `License: GPL-2.0-or-later`.

## Code of conduct

Be respectful. Be honest. Be transparent. Disagreements are welcome;
personal attacks are not. If you are unsure whether a comment is
appropriate, err on the side of caution.

## Thank you

Every contribution — bug reports, documentation improvements, audit
suites, features — makes Ultimate Performance better. Thank you for your
time.
