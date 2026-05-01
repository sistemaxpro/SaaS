<?php
$sql ="SELECT 
empresa, 
activo, 
dbase, 
logos, 
email, 
ruc, 
menu, 
coneccion, 
server, 
user, 
password,
formato_ticket,
formato_ticket_legal,
formato_A4_comun,
formato_A4_legal,
direccion,
telefono,
ciudad,
pais,
server_imagen,
tipo_programa,
version,
software,
cant_item_buscador,
web,
web_url,
web_cs,
web_ck,
web_b2b_grupo
FROM empresa WHERE BINARY  id_empresa = '".[id_empresa]."'";
sc_lookup(rs, $sql);
if(count({rs}) == 0)
	{
		sc_error_message("Empresa asignada a este usuario no existe");
		sc_error_exit();
	}
else
	{	
		$empresa    		 	= {rs[0][0]};
		$activo             	= {rs[0][1]};
		$db                 	= {rs[0][2]};
		$logo				 	= {rs[0][3]};
		$EmailEmpresa		 	= {rs[0][4]};
		$RucEmpresa		 		= {rs[0][5]};
		$archivo_logo_empresa	= {rs[0][3]};
		$menu 					= {rs[0][6]};
		$coneccion 				= {rs[0][7]};
		$server 				= {rs[0][8]};
		$user 					= {rs[0][9]};
		$password 				= {rs[0][10]};
		$formato_ticket 		= {rs[0][11]};
		$formato_ticket_legal 	= {rs[0][12]};
		$formato_A4_comun 		= {rs[0][13]};
		$formato_A4_legal 		= {rs[0][14]};
		$DireccionEmpresa 		= {rs[0][15]};
		$TelefonoEmpresa 		= {rs[0][16]};
		$server_imagen 			= {rs[0][19]};
		$modulo 				= {rs[0][20]};
		$version 				= {rs[0][21]};
		$software 				= {rs[0][22]};
		$cant_item_buscador 	= {rs[0][23]};
		$web				 	= {rs[0][24]};
		$web_url			 	= {rs[0][25]};
		$web_cs				 	= {rs[0][26]};
		$web_ck				 	= {rs[0][27]};
		$web_b2b_grupo			= {rs[0][28]};

	    sc_set_global($activo);
		sc_set_global($logo);
		sc_set_global($archivo_logo_empresa);
		sc_set_global($empresa);
		sc_set_global($db);
		sc_set_global($EmailEmpresa);
		sc_set_global($RucEmpresa);						
		sc_set_global($menu);
		sc_set_global($coneccion);
		sc_set_global($server);
		sc_set_global($user);
		sc_set_global($password);
		sc_set_global($formato_ticket);
		sc_set_global($formato_ticket_legal);
		sc_set_global($formato_A4_comun);
		sc_set_global($formato_A4_legal);
		sc_set_global($DireccionEmpresa);
		sc_set_global($TelefonoEmpresa);
		sc_set_global($server_imagen);
		sc_set_global($modulo);
		sc_set_global($cant_item_buscador);
		sc_set_global($web);
		sc_set_global($web_url);
		sc_set_global($web_cs);
		sc_set_global($web_ck);
		sc_set_global($web_b2b_grupo);

	   
	
        $host= $_SERVER["HTTP_HOST"];
		[server_web] = $host;
	    $doc_root = $_SERVER['DOCUMENT_ROOT'];
        $server_name = $_SERVER['SERVER_NAME'];
    
        if($server_name == 'sistemax.com.py')
            {
            $url_sistema = 'https://sistemax.com.py';
            }
        else
            {
            $url_sistema = 'https://'.$host;
            }
    


		$arr_conn['server'] = [server];
		$arr_conn['user'] = [user];
		$arr_conn['password'] = [password];
	
	    $string = $_SERVER['HTTP_REFERER'];
		if(stristr($string, 'scriptcase') === FALSE) //Produccion
			{
				$url_sistema = 'https://sistemax.com.py/';
				$subruta = 'https://sistemax.com.py/_lib/file/';
				$subruta_imagen = 'https://sistemax.com.py/_lib/file/img/';
				$url_img = 'https://sistemax.com.py/_lib/file/img/';
				//$dir_img = '/var/www/html/sistemax/_lib/file/img/';
				$dir_img = '/home/fabio/web/sistemax.com.py/public_html/_lib/file/img/';
			
				//$path_sistema = '/var/www/html/sistemax/';
				$path_sistema = '/home/fabio/web/sistemax.com.py/public_html/';
			}
		else //Desarrollo
			{
				$url_sistema = 'http://45.160.33.74:8092/scriptcase/app/smx/';
				$subruta = 'http://45.160.33.74:8092/scriptcase/file/';
				$subruta_imagen = 'http://45.160.33.74:8092/scriptcase/file/img/';
				$url_img = 'http://45.160.33.74:8092/scriptcase/file/img/';
				$dir_img = '/opt/Scriptcase/v9-php81/wwwroot/scriptcase/file/img/';
				$path_sistema = '/opt/Scriptcase/v9-php81/wwwroot/scriptcase/app/smx/';
			}
	//$subruta = 'https://sistemax.com.py/_lib/file/';
	//$subruta_imagen = 'https://sistemax.com.py/_lib/file/img/';
	 $caja = traedatos([db].".cajas","caja","id_caja",[caja_def]);
		$sucursal = traedatos([db].".sucursales","sucursal","id_sucursal",[sucursal_def]);
		$id_cliente_def = traedatos([db].".clientes","id","numero",[ruc_def]);
	    sc_set_global($sucursal);
	    sc_set_global($caja);
	    sc_set_global($id_cliente_def);
	
		if([equipo]=='pc')
		{
			if([invisible] == 1)
				{
					$datos_sistema = '
					<span>
						<i class="fas fa-industry text-info mx-1"></i> '. [empresa].'   
						<i class="fas fa-database text-info mx-1"></i> '. [db].'
						<i class="fas fa-server text-info mx-1"></i> '.[server] .' 
						<i class="fas fa-home text-info mx-1"></i> '.[sucursal].'
						<i class="fas fa-cash-register text-info mx-1"></i> '.[caja_def].' - '.[caja].'
						<i class="fas fa-users text-info mx-1"></i> '.[id_grupo_usuario].' - '.[grupo_usuario].'
					</span>
					';
				}
			else
				{
					$datos_sistema = '
					<span>
						<i class="fas fa-industry text-info mx-1"></i> '. [empresa].'   
						<i class="fas fa-home text-info mx-1"></i> '.[sucursal].'
						<i class="fas fa-cash-register text-info mx-1"></i> '.[caja_def].' - '.[caja].'
						<i class="fas fa-users text-info mx-1"></i> '.[id_grupo_usuario].' - '.[grupo_usuario].'
					</span>
					';
				}
		}
	else
		{
			$datos_sistema = '
				<span class="fs--2">
					<i class="fas fa-home text-info mx-1"></i> '.[sucursal].'
					<i class="fas fa-cash-register text-info mx-1"></i> '.[caja].'
				</span>	
				';	
		}	
	
		sc_set_global($url_sistema);
		sc_set_global($path_sistema);
		sc_set_global($datos_sistema);
		sc_set_global($dir_img);
		sc_set_global($subruta);
		sc_set_global($subruta_imagen);
		sc_set_global($url_img);
		$ruta_imagen_camiones = $subruta_imagen.'camiones/';
		sc_set_global($ruta_imagen_camiones);
		$ruta_imagen_fotos = $subruta_imagen.'fotos/';
		sc_set_global($ruta_imagen_fotos);
		$ruta_imagen_firmas = $subruta_imagen.'firmas/';
		sc_set_global($ruta_imagen_firmas);
		$ruta_imagen_empresas = $subruta_imagen.'empresa/';
		sc_set_global($ruta_imagen_empresas);
		$ruta_imagen_usuarios = $subruta_imagen.'usuarios/';
		sc_set_global($ruta_imagen_usuarios);
		$ruta_imagen_mercaderias = $subruta_imagen.'productos/'.[db].'/';
		sc_set_global($ruta_imagen_mercaderias);
		$ruta_imagen_sucursales = $subruta_imagen.'sucursales/';
		sc_set_global($ruta_imagen_sucursales);
		$ruta_imagen_camiones = $subruta_imagen.'camiones/';
		sc_set_global($ruta_imagen_camiones);
		$ruta_imagen_carretas = $subruta_imagen.'carretas/';
		sc_set_global($ruta_imagen_carretas);
	    $ruta_imagen_cedulas = $subruta_imagen.'cedulas/';
		sc_set_global($ruta_imagen_cedulas);
		$ruta_imagen_sistemas = $subruta_imagen.'sistemas/';
		sc_set_global($ruta_imagen_sistemas);

		$logo = [ruta_imagen_empresas].$logo;
	    $logo_sistemax = [ruta_imagen_empresas]."sistemax.png";
		sc_set_global($logo);
	    sc_set_global($logo_sistemax);
	
		sc_lookup(dsql,"select DISTINCT database_name from mysql.innodb_table_stats where database_name = '".[db]."'"); 
		if(!isset({dsql[0][0]}))
			{
		$sql = "CREATE DATABASE IF NOT EXISTS ".[db];
		sc_exec_sql($sql);
			sc_commit_trans();
			}

		$arr_conn['database'] = [db];
		sc_connection_edit("tienda", $arr_conn);

		$grupo	= [empresa];	
		sc_set_global($grupo);
		   
	
	$sql ="select tipo_precio_df,
	moneda_df,
	tipo_doc_df,
	forma_pago_df,
	tipo_venta_df,
	id_cli_df
	from [db].cajas where id_caja = '[id_caja]'";
	sc_lookup(rs,$sql);
	if(isset({rs[0][0]}))
		{
		$tipo_precio_def= {rs[0][0]};
		$moneda_def 	= {rs[0][1]};
		$tipo_doc_def 	= {rs[0][2]};
		$forma_pago_def	= {rs[0][3]};
		$tipo_venta_def	= {rs[0][4]};
		$id_cli_def     = {rs[0][5]};
		}
	else
		{
		$tipo_precio_def= 1;
		$moneda_def 	= 1;
		$tipo_doc_def 	= 1;
		$forma_pago_def	= 1;
		$tipo_venta_def	= 1;
		$id_cli_def     = 1;		
		}
	sc_set_global($tipo_precio_def);
	sc_set_global($moneda_def);
	sc_set_global($tipo_doc_def);
	sc_set_global($forma_pago_def);
	sc_set_global($tipo_venta_def);	
	sc_set_global($id_cli_def);
    
	$sql = "select sucursal, direccion, telefono, logo from [db].sucursales where id_sucursal = '[id_sucursal]'";
	sc_lookup(rs,$sql);
	if(isset({rs[0][0]}))
		{
				$sucursal	= {rs[0][0]};
				$direccion = {rs[0][1]};
				$telefono = {rs[0][2]};
				$_logo_suc = {rs[0][3]};
				if ($_logo_suc == '')
					{
					$sql="update [db].sucursales set logo = '".$archivo_logo_empresa."' where id_sucursal = 													'[id_sucursal]'";
					sc_exec_sql($sql);
					}

		}
	else
		{
				$sucursal = 'default';
				$direccion = 'Paraguay';
				$telefono = '0800';

		}		
	sc_set_global($sucursal);
	sc_set_global($direccion);
	sc_set_global($telefono);	
	}


$directorio = 'var/www/html/sistemax/_lib/file/img/usuarios/';
$foto = buscarArchivoUsuario([id_login],$directorio);
[avatar] = 'https://sistemax.com.py/_lib/file/img/usuarios/'.$foto;
[logo_empresa] = 'https://sistemax.com.py/_lib/file/img/empresa/'.$archivo_logo_empresa;

// --- EXPORTAR A LOCALSTORAGE ---
?>
<script>
(function() {
    const config = {
        id_empresa: '<?php echo [id_empresa]; ?>',
        empresa: '<?php echo addslashes($empresa); ?>',
        db_empresa: '<?php echo addslashes($db); ?>',
        ruc_empresa: '<?php echo addslashes($RucEmpresa); ?>',
        email_empresa: '<?php echo addslashes($EmailEmpresa); ?>',
        logo_empresa: '<?php echo addslashes([logo_empresa]); ?>',
        avatar_empresa: '<?php echo addslashes([avatar]); ?>',
        sucursal_empresa: '<?php echo addslashes($sucursal); ?>',
        direccion_empresa: '<?php echo addslashes($DireccionEmpresa); ?>',
        telefono_empresa: '<?php echo addslashes($TelefonoEmpresa); ?>',
        id_login: '<?php echo [id_login]; ?>',
        id_caja: '<?php echo [id_caja] ?? ''; ?>',
        url_sistema: '<?php echo addslashes($url_sistema); ?>',
        subruta_imagen: '<?php echo addslashes($subruta_imagen); ?>',
        datos_sistema: `<?php echo addslashes($datos_sistema); ?>`,
        tipo_precio_def: '<?php echo $tipo_precio_def ?? 1; ?>',
        moneda_def: '<?php echo $moneda_def ?? 1; ?>'
    };

    // Guardar objeto completo
    localStorage.setItem('sc_empresa_config', JSON.stringify(config));
    
    // Guardar llaves individuales para acceso directo
    Object.keys(config).forEach(key => {
        localStorage.setItem('sc_' + key, config[key]);
    });
    
    console.log('Variables de empresa exportadas a localStorage');
})();
</script>
<?php

function buscarArchivoUsuario($id_usuario,$directorio) {
    // Construye el patrón de búsqueda para aceptar cualquier nombre de archivo y extensión de imagen común
    $patron = $directorio . $id_usuario . "*.{jpg,jpeg,png,gif,bmp,webp}";

    // Utiliza glob para buscar archivos que coincidan con el patrón
    // El flag GLOB_BRACE permite buscar múltiples extensiones
    $resultados = glob($patron, GLOB_BRACE);

    // Verifica si se encontraron archivos
    if (!empty($resultados)) {
        // Si se encontró al menos un archivo, retorna el primero
            $foto = basename($resultados[0]);
		   	return $foto;
    } else {
        // Si no se encontró ningún archivo, retorna la imagen fija
		$rubro = traedatos('empresa', 'rubro', 'id_empresa', [id_empresa]);
		$genero = traedatos('serproc1.apps_users', 'genero', 'id_login', $id_usuario);
		$login = traedatos('serproc1.apps_users', 'login', 'id_login', $id_usuario);
		$group_id = traedatos('serproc1.apps_users_groups', 'group_id', 'login', $login);
		$actividad = traedatos2('serproc1.apps_groups', 'description', "group_id=$group_id and id_grupo = [id_empresa]");
		$genero = $genero == 1 ? 'Male' : 'Female';
		$rolesYActividades = [
			'Venta' => ['balconista', 'atencion', 'balcon', 'venta', 'vendedora', 'ventas', 'operador', 'operadora'],
			'Frentista' => ['playero', 'atencion', 'playa', 'playera'],
			'Admin' => ['Supervision', 'Gestion', 'Liderazgo', 'admin', 'Administrador', 'encargado', 'Admin'],
			'Soporte' => ['soporte tecnico', 'cliente ayuda', 'servicio técnico', 'mecanico', 'electricista', 'soporte'],
			'Contab' => ['contabilidad', 'contador', 'contadora', 'contable', 'auditoria','control']
		];

		// Buscar un rol para una actividad
		$roles = buscarRol($actividad, $rolesYActividades);
		$foto = $rubro.$roles.$genero . '.webp';
		
		$filePath = $directorio.$foto;
		// Supongamos que $filePath es la ruta completa al archivo, incluyendo el nombre del archivo

		// Nombre fijo para devolver si el archivo no existe
		$nombreFijo = $rubro.'generico'.$genero . '.webp';

			// Verificar si el archivo existe
			if (file_exists($filePath)) {
				return $foto;
			} else {
				return $nombreFijo;
			}

		}
	}

function buscarRol($actividad, $rolesYActividades) {
    // Convertir la actividad buscada a minúsculas
    $actividad = strtolower($actividad);

    // Recorrer el array de roles y actividades
    foreach ($rolesYActividades as $rol => $actividades) {
        // Convertir todas las actividades a minúsculas y buscar la actividad
        foreach ($actividades as $item) {
            if (strtolower($item) == $actividad) {
				$rol = $rol == '' ? 'generico':$rol;
                return $rol ?? 'generico';  // Retorna el rol si la actividad coincide
            }
        }
    }

    return "generico";  // Retorna esto si no se encuentra la actividad
}
