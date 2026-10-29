#!/bin/bash
# usage: r7-chain.sh <fixture-label> <db-port> <app-port> <collation> [steps...]
# Batch 7R regression chain against one disposable fixture (helpers verify the fixture datadir before any access).
set -u
S=<LOCAL_SCRATCHPAD>
R=/c/PRISM_Workspaces/prism-v13-release-clean/.batch7r-worktree
PHP=/c/xampp/php/php.exe
fx=$1; export PRISM_TEST_DB_PORT=$2 PRISM_TEST_APP_PORT=$3 PRISM_TEST_DB_COLLATION=$4; shift 4
export PRISM_TEST_REVIEW_DIR="$(cygpath -w $S/$fx)"
export PRISM_TEST_PLAYWRIGHT='C:\PRISM_Workspaces\prism-v13-release-clean\PRISM_V13_UI_REMEDIATION_AUDIT\browser-tools\node_modules\playwright-core'
E=$S/ev/runs/$fx; mkdir -p $E
cd $R
L=$E/chain.log
step() { echo "[$(date -Iseconds)] $*" | tee -a $L; }
schema() { echo '{"action":"schema"}' | $PHP maintenance/ierb-group-tests/fixture.php; }
run() { # label, env..., -- script
  local label=$1; shift; local envs=(); while [ "$1" != "--" ]; do envs+=("$1"); shift; done; shift
  mkdir -p $E/$label; rm -rf "$S/$fx/sandbox/app/storage/auth_rate_limits"
  for f in test-results.json test-results-documents.json test-results-backend.json test-failure.json; do rm -f "$S/$fx/$f"; done
  local t0=$(date -Iseconds)
  env PRISM_TEST_EVIDENCE_DIR="$(cygpath -w $E/$label)" "${envs[@]}" node "$@" > $E/$label/console.log 2>&1; local code=$?
  for f in test-results.json test-results-documents.json test-results-backend.json test-failure.json responsive-results.json; do [ -f "$S/$fx/$f" ] && mv "$S/$fx/$f" $E/$label/; done
  echo "{\"label\":\"$label\",\"exit\":$code,\"start\":\"$t0\",\"end\":\"$(date -Iseconds)\",\"env\":\"${envs[*]}\",\"fixture\":\"$fx\",\"collation\":\"$PRISM_TEST_DB_COLLATION\",\"base\":\"f944220+7R working tree\"}" > $E/$label/receipt.json
  step "$label exit=$code $(tail -1 $E/$label/console.log | cut -c1-300) schema=$(schema)"
}
step "start schema=$(schema)"
for s in "$@"; do case $s in
  s11) run 01-schema11-full-batch6 PRISM_TEST_BATCH6=1 -- maintenance/ierb-group-tests/run.cjs ;;
  foundation) mkdir -p $E/02-foundation; PRISM_TEST_EVIDENCE_DIR="$(cygpath -w $E/02-foundation)" node maintenance/ierb-v14-tests/run.cjs > $E/02-foundation/console.log 2>&1; step "02-foundation exit=$? $(tail -1 $E/02-foundation/console.log | cut -c1-300) schema=$(schema)" ;;
  apply12) step "apply-for-regression $($PHP maintenance/ierb-v14-tests/cases.php apply-for-regression 2>&1 | tail -c 300) schema=$(schema)" ;;
  s12) run 03-schema12-full-all-flags PRISM_TEST_SCHEMA_VERSION=12 PRISM_TEST_V14_DOCUMENTS=1 PRISM_TEST_PROJECT_GROUP=1 PRISM_TEST_REPORT_SCHEMA14=1 PRISM_TEST_MONITOR_SCHEMA15=1 PRISM_TEST_BATCH6=1 -- maintenance/ierb-group-tests/run.cjs ;;
  b4) run 04-batch4-documents PRISM_TEST_SCHEMA_VERSION=12 PRISM_TEST_PHASE=documents PRISM_TEST_BATCH4=1 -- maintenance/ierb-group-tests/run.cjs ;;
  b5) run 05-batch5-documents PRISM_TEST_SCHEMA_VERSION=12 PRISM_TEST_PHASE=documents PRISM_TEST_BATCH5=1 -- maintenance/ierb-group-tests/run.cjs ;;
  install15) step "install-15 $(echo '{"action":"install"}' | $PHP maintenance/ierb-monitor-tests/fixture.php 2>&1 | head -c 400) schema=$(schema)" ;;
  s15) run 06-batch6-schema15 PRISM_TEST_SCHEMA_VERSION=15 PRISM_TEST_PHASE=documents PRISM_TEST_BATCH6=1 -- maintenance/ierb-group-tests/run.cjs ;;
  r7) run 10-batch7r-schema15 PRISM_TEST_SCHEMA_VERSION=15 PRISM_TEST_PHASE=documents PRISM_TEST_BATCH7R=1 -- maintenance/ierb-group-tests/run.cjs ;;
  teardown)
    step "cleanup-15 $(echo '{"action":"cleanup"}' | $PHP maintenance/ierb-monitor-tests/fixture.php 2>&1 | head -c 300) schema=$(schema)"
    step "cleanup-13 $(echo '{"action":"cleanup"}' | $PHP maintenance/ierb-v14-tests/documents-fixture.php 2>&1 | head -c 300) schema=$(schema)"
    step "rollback-12 $($PHP maintenance/ierb-v14-tests/cases.php rollback-empty 2>&1 | tail -c 300) schema=$(schema)" ;;
  s11b) run 07-schema11-rerun -- maintenance/ierb-group-tests/run.cjs ;;
esac; done
step "end"
