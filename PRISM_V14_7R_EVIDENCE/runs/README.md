# Regression run evidence (synthetic fixtures only)

The results table and explanations are in [PRISM_V14_7R_SECURITY_AND_REGRESSION.md](../../PRISM_V14_7R_SECURITY_AND_REGRESSION.md) §2.

| Folder | What it is |
| --- | --- |
| `fx104/`, `fx1232/` | **Final** chains on freshly built fixtures (MariaDB 10.4 unicode_ci; MariaDB 12.3.2 general_ci), run one after the other on the final code. `fx1232/01-…-intermittent-failure` keeps the single pre-existing Batch 6 UI intermittency; `fx1232/01-…` is its rerun. |
| `fx104-attempt1-parallel-port-exhaustion/` | Chains run in parallel: Windows ephemeral-port exhaustion (environment) |
| `fx1232-attempt2-residue/` | Found the track-sheet "Not recorded" regression (run 04) and the Batch 3 test residue (run 05). Run 06 was stopped deliberately (connection refused). |
| `fx104-attempt2-slug/` | Found the iconv transliteration portability defect (run 10) |
| `fx1232-attempt3-dialog/`, `fx1232-debug-dialog-loops/` | Found the onboarding-tour focus race in the run-10 browser check; includes the captured DOM diagnostics and the 3 of 3 confirmation loops |

Per run: `receipt.json` (label, exit code, times, flags, fixture, collation), `console.log` (every assertion), suite result JSON, and `test-failure.json` where a run failed. Screenshots are kept only for run 10. The full screenshot and PDF sets stay with the operator's local copy.

These files contain no credentials and no personal data. All accounts are synthetic `example.test` identities.
