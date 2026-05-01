<?php 
	require 'src/php-sifen.php';

	$key = new \sifen\KEY('noenviar/AKTIV.p12', 'asd');
	$sifen = new \sifen\Sifen('test', $key);
	$xmlRequest = $sifen->anular('01801107474001001000300622023051310000000021');
	$respuesta = $sifen->enviarEvento($xmlRequest);
	if($respuesta['status'] == 'ok' && !empty($respuesta['response'])) {
		echo $sifen->getId()."<br />"; 
		echo $sifen->getFecProc()."<br />";
		echo $sifen->getEstRes()."<br />";
		echo $sifen->getProtAut()."<br />";
		echo $sifen->getCodRes()."<br />";
		echo $sifen->getMsgRes()."<br />";
		echo $sifen->getProtConsLote()."<br />";
		echo $sifen->getTpoProces()."<br />";
	} else {
		die("No hubo respuesta");
	}
?>