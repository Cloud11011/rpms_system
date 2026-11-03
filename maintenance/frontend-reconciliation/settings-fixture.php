<?php
/** Synthetic, datadir-bound Settings rehearsal. Never loads deployed configuration. */
if (PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/schema_v16.php';
require_once $root.'/includes/admin_settings_schema.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
foreach (['PRISM_ALLOW_SCHEMA_V16_MIGRATION','PRISM_ALLOW_SETTINGS_MIGRATION','PRISM_ALLOW_RESEARCH_ONBOARDING_MIGRATION'] as $name) putenv($name.'=1');
foreach (['PRISM_SCHEMA_V16_EXPECT_DB','PRISM_SETTINGS_EXPECT_DB','PRISM_RESEARCH_ONBOARDING_EXPECT_DB'] as $name) putenv($name.'=prism_group_synthetic_v11');
try {
    $result=match($input['action']??'') {
        'pin-contract'=>(function()use($pdo){
            migrate_schema_v16($pdo,'apply');
            $result=[];
            foreach(prism_admin_settings_schema_tables(schema_extension_collation($pdo)) as $table=>$ddl) {
                $pdo->exec($ddl);
                $result[$table]=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
            }
            return $result;
        })(),
        default=>throw new RuntimeException('Unknown Settings fixture action.'),
    };
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();echo json_encode(['ok'=>false,'type'=>get_class($e),'message'=>$e->getMessage()],JSON_THROW_ON_ERROR);exit(1);}
