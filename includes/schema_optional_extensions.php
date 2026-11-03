<?php
/** Only explicitly installed, independently verified extensions may add tables. */
function schema_optional_extension_tables(PDO $pdo): array
{
    $tables=[];
    foreach (['research_onboarding_v1','prism_settings_v1'] as $key) {
        $q=$pdo->prepare('SELECT v FROM schema_meta WHERE k=?');$q->execute([$key]);$value=$q->fetchColumn();
        if ($value===false) continue;
        if ($value!=='1') throw new RuntimeException('Unknown extension contract version: '.$key);
        if ($key==='research_onboarding_v1') {
            require_once __DIR__.'/schema_research_onboarding.php';
            research_onboarding_extension_verify($pdo);
            $tables=array_merge($tables,array_keys(research_onboarding_tables()));
        } else {
            require_once __DIR__.'/admin_settings_schema.php';
            prism_admin_settings_schema_verify($pdo);
            $tables=array_merge($tables,array_keys(prism_admin_settings_schema_tables()));
        }
    }
    return $tables;
}
