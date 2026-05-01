<?php
$file = '/var/www/html/scriptcase/app/smx/logs/xml_debug_last.xml';
if (!file_exists($file)) {
    die("Debug file not found: $file\n");
}

$content = file_get_contents($file);
echo "Debug XML Size: " . strlen($content) . " bytes\n";

// Extract Base64 from <xsd:xDE> or <xDE>
if (preg_match('/<xsd:xDE>(.*?)<\/xsd:xDE>/s', $content, $matches) || preg_match('/<xDE>(.*?)<\/xDE>/s', $content, $matches)) {
    $b64 = trim($matches[1]);
    echo "Base64 Length: " . strlen($b64) . "\n";
    
    $zipData = base64_decode($b64);
    if (!$zipData) {
        die("Base64 decode failed.\n");
    }
    
    $tmpZip = __DIR__ . '/temp_analysis.zip';
    file_put_contents($tmpZip, $zipData);
    
    $zip = new ZipArchive;
    if ($zip->open($tmpZip) === TRUE) {
        if ($zip->locateName('xml.xml') !== false) {
            $innerXml = $zip->getFromName('xml.xml');
            echo "\n--- INNER XML CONTENT START ---\n";
            
            // Format XML for readability
            $dom = new DOMDocument;
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            if (@$dom->loadXML($innerXml)) {
                echo $dom->saveXML();
            } else {
                echo "DOM Load Failed. Raw Content:\n";
                echo $innerXml;
            }
            echo "\n--- INNER XML CONTENT END ---\n";
        } else {
            echo "xml.xml NOT FOUND in ZIP.\n";
            echo "Files in ZIP:\n";
            for($i = 0; $i < $zip->numFiles; $i++) {
                 echo "- " . $zip->getNameIndex($i) . "\n";
            }
        }
        $zip->close();
    } else {
        echo "Failed to open ZIP.\n";
    }
    unlink($tmpZip);
} else {
    echo "Could not find <xDE> tag in debug file.\n";
    echo "Preview: " . substr($content, 0, 500) . "...\n";
}
?>
