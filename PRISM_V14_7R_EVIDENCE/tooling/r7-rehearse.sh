#!/bin/bash
# usage: r7-rehearse.sh <label> <repo> <port> <source-db> [repair]
# Clones a restored schema-11 database, then runs 11->12->13->14->15 with the given repository's CLI runners.
set -u
S=<LOCAL_SCRATCHPAD>
PHP=/c/xampp/php/php.exe
label=$1; repo=$2; port=$3; src=$4; repair=${5:-}
B=$S/mdb1232/mariadb-12.3.2-winx64/bin
case $port in 13537) B=<LOCAL_SCRATCHPAD>/mdb1011/mariadb-10.11.10-winx64/bin;; 13547) B=<LOCAL_SCRATCHPAD>/mdb114/mariadb-11.4.4-winx64/bin;; 13527|13647) B=/c/xampp/mysql/bin;; esac
MY="$B/mariadb.exe --host=127.0.0.1 --port=$port -uroot -pprism_synthetic_only"; [ -x "$B/mariadb.exe" ] || MY="$B/mysql.exe --host=127.0.0.1 --port=$port -uroot -pprism_synthetic_only"
DUMP="$B/mariadb-dump.exe"; [ -x "$DUMP" ] || DUMP="$B/mysqldump.exe"
O=$S/ev/rehearsal/$label; rm -rf "$O"; mkdir -p "$O"
DB=r7_$label
if [[ "$src" == *.sql ]]; then $MY -e "DROP DATABASE IF EXISTS \`$DB\`" 2>>"$O/errors.log"; sed "s/prism_group_synthetic_v11/$DB/g" "$src" | $MY 2>>"$O/errors.log"; echo "import exit $?" >> "$O/steps.log"; else
$MY -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>>"$O/errors.log"
$DUMP --host=127.0.0.1 --port=$port -uroot -pprism_synthetic_only --single-transaction --routines --triggers "$src" 2>>"$O/errors.log" | $MY "$DB" 2>>"$O/errors.log"
echo "clone exit ${PIPESTATUS[0]} ${PIPESTATUS[1]}" >> "$O/steps.log"; fi
if [ "$repair" = "repair" ]; then
  # Staging-only export-fidelity repair: phpMyAdmin dropped this frozen v10/v11 constraint from the dump.
  $MY "$DB" -e "ALTER TABLE staff_registration_code ADD CONSTRAINT staff_code_singleton CHECK (slot=1)" 2>>"$O/errors.log"
  echo "fidelity repair: staff_code_singleton CHECK re-added (exit $?)" >> "$O/steps.log"
fi
export R7_REPO=$repo PRISM_MIGRATION_DB_DSN="mysql:host=127.0.0.1;port=$port;dbname=$DB;charset=utf8mb4" PRISM_MIGRATION_DB_USER=root PRISM_MIGRATION_DB_PASSWORD=prism_synthetic_only
h() { $PHP "$S/r7-rehearsal.php" "$@"; }
h info > "$O/server-info.json"
h verify > "$O/verify-11-baseline.json"; echo "baseline $(cat "$O/verify-11-baseline.json")" >> "$O/steps.log"
h fingerprint > "$O/fingerprint-before.json"
declare -A RUN=([12]=migrate-ierb-v14.php [13]=migrate-research-projects.php [14]=migrate-summary-history.php [15]=migrate-academic-years.php)
for n in 12 13 14 15; do
  export PRISM_ALLOW_SCHEMA_V${n}_MIGRATION=1 PRISM_SCHEMA_V${n}_EXPECT_DB=$DB
  t0=$(date +%s%N)
  $PHP "$repo/maintenance/${RUN[$n]}" dry-run > "$O/v$n-dry-run.json" 2> "$O/v$n-dry-run.err"; d=$?
  $PHP "$repo/maintenance/${RUN[$n]}" apply > "$O/v$n-apply.json" 2> "$O/v$n-apply.err"; a=$?
  t1=$(date +%s%N)
  h verify > "$O/verify-$n.json"
  echo "v$n dry-run=$d apply=$a ms=$(( (t1-t0)/1000000 )) verify=$(cat "$O/verify-$n.json") err=$(cat "$O/v$n-apply.err")" >> "$O/steps.log"
  if [ $a -ne 0 ]; then
    $MY -N -e "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='$DB' ORDER BY 1" > "$O/tables-after-failure.txt" 2>>"$O/errors.log"
    $PHP "$repo/maintenance/${RUN[$n]}" dry-run > "$O/v$n-dry-run-after-failure.json" 2> "$O/v$n-dry-run-after-failure.err"
    echo "v$n post-failure dry-run exit=$? $(cat "$O/v$n-dry-run-after-failure.err")" >> "$O/steps.log"
    break
  fi
done
h fingerprint > "$O/fingerprint-after.json"
node "$(cygpath -m "$S")/fpcompare.cjs" "$(cygpath -m "$O")" >> "$O/steps.log"
cat "$O/steps.log"
