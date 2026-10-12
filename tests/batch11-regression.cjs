'use strict';
// Reuse the accepted 27-suite runner without overwriting its historical output directory.
const fs=require('node:fs'),path=require('node:path'),Module=require('node:module');
let source=fs.readFileSync(path.join(__dirname,'v10-batch1075-regression.cjs'),'utf8');
source=source.replace("path.join(__dirname,'v10-batch1075-results','remediation','cli')","path.join(__dirname,'batch11-results','cli-final')");
const runner=new Module(__filename,module);runner.filename=__filename;runner.paths=module.paths;runner._compile(source,__filename);
