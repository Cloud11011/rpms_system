<?php
$root='C:/PRISM_Workspaces/prism-v13-release-clean/.batch7r-worktree';
require $root.'/includes/report_pdf.php'; require $root.'/vendor/autoload.php';
$inputs=['Peñaflor','Batà','São José','Ñuñoa – “Pag-aaral” sa Lungsod €5','Łódź','研究','😀'];
$results=[];
foreach ($inputs as $in) {
    $pdf=make_pdf('Título de investigación: '.$in,['# Research title', $in, ['headers'=>['Research Title','Stage'],'widths'=>[300,212],'rows'=>[[$in,'Initial Submission']]]]);
    $text=(new Smalot\PdfParser\Parser())->parseContent($pdf)->getText();
    $results[]=['input'=>$in,'bodyContainsExact'=>str_contains($text,$in),'substitutionDisclosed'=>str_contains($text,'outside the PDF font'),
        'extracted'=>mb_substr(preg_replace('/\s+/u',' ',$text),0,160)];
}
file_put_contents($argv[1],json_encode(['php'=>PHP_VERSION,'os'=>PHP_OS_FAMILY,'intl'=>class_exists('Normalizer'),'results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
file_put_contents(dirname($argv[1]).'/unicode-sample.pdf',make_pdf('Unicode sample — Peñaflor, São José',['# Titles','Peñaflor; Batà; São José; Ñuñoa – “Pag-aaral” €5; Łódź; 研究; 😀']));
echo file_get_contents($argv[1]);
