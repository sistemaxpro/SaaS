<?php
require_once __DIR__ . '/_lib/php-sifen3/src/php-sifen.php';

$certPath = __DIR__ . '/_lib/php-sifen3/certificados/80118689.p12';
$certPass = '3nvcEcwW';

$client = new \SifenWSClient('prod');
$client->setPassphrase($certPass)->setCertificateFromPath($certPath);

$cdcs = [
    '01801186897001001000000422026010410000000010', // ID 3
    '01801186897001001000000522026010410000000010'  // ID 4
];

foreach ($cdcs as $cdc) {
    echo "CDC: $cdc\n";
    $res = $client->consulta('siConsDE', ['dCDC' => $cdc]);
    if ($res['status'] === 'ok') {
        $xml = new SimpleXMLElement($res['response']);
        $ns = $xml->getNamespaces(true);
        $body = $xml->children($ns['env'])->Body;
        $ret = $body->children($ns['ns2'])->rRetConsDe;
        echo "Estado: " . $ret->gResProc->dEstRes . "\n";
        if (isset($ret->gResProc->gResProcEVe)) {
            echo "Eventos:\n";
            foreach ($ret->gResProc->gResProcEVe as $eve) {
               echo " - " . $eve->dMsgRes . " (" . $eve->dEstRes . ")\n";
            }
        }
    } else {
        echo "Error: " . $res['error'] . "\n";
    }
    echo "-------------------\n";
}
