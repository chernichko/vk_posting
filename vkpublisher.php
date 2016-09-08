<?php

use VK\VK;

class Vkpublisher extends MI_Controller {

	private $APP_ID;
	private $APP_SECRET;
	private $TOKEN;
	private $GROUP_ID;
	private $RULES;
	private $VK; 

	private $NEW = 'new';
	private $BESTPRICE = 'bp';
	private $HITS = 'hits';

	function __construct()
	{
		parent::__construct();
		$this->load->model('tovar');
		$this->config->load('vk_config');
		$this->APP_ID = $this->config->item('app_id');
		$this->APP_SECRET = $this->config->item('app_secret');
		$this->TOKEN = $this->config->item('access_token');
		$this->GROUP_ID = $this->config->item('group_id');
		$this->RULES = $this->config->item('rules');

		$this->VK = new VK($this->APP_ID, $this->APP_SECRET, $this->TOKEN);
		// обязательно указывать версию API!!!
		// https://new.vk.com/apiclub?w=wall-1_340400
		$this->VK->setApiVersion('5.53');
	}


	/**
	 * Кликнув по ссылке вы  получите access_token, который надо будет ручками сохранить
	 * @return [type] [description]
	 */
	public function restore_token()
	{
		$authorize_url = $this->VK->getAuthorizeURL($this->RULES, 'https://api.vk.com/blank.html');
		echo '<a href="' . $authorize_url . '">Sign in with VK</a><br>';
	}


	public function new_goods()
	{
		$this->_publishing($this->NEW);
	}


	public function hit_goods()
	{
		$this->_publishing($this->HITS);
	}


	public function best_price_goods()
	{
		$this->_publishing($this->BESTPRICE);
	}


	private function _publishing($type)
	{   
		$goods_for_vk = $this->_get_products($type);
		if (count($goods_for_vk) == 0) {
			echo '<pre> NOTHING </pre>';
			return 0;
		}
		try{
			// Получить адрес сервера, куда загружать
			$load_server_url = $this->_getWallUploadServer($this->GROUP_ID);

			$count = count($goods_for_vk);
			$photos = array();

			// ограничение в 4 штуки связанно с ограничениями API VK
			for ($i=0; $i < $count; $i+=4) { 
				// Передать картинки POST'ом 
				$resp_photo_load = $this->_send_picture(array_slice($goods_for_vk, $i, 4), $load_server_url);


				// Сохранить результаты передачи
				$resp_save_wall = $this->VK->api('photos.saveWallPhoto', array(
					'group_id' => $this->GROUP_ID,
					'server' => $resp_photo_load->server,
					'photo' => $resp_photo_load->photo,
					'hash' => $resp_photo_load->hash
				));
			
				$photos = array_merge($photos ,$resp_save_wall['response']);
				sleep(1);
			}
			// echo '<pre><b>Сохранённые картинки </b> ' . var_export($photos, true) . '</pre>';
			$count_attachments = count($photos);

			// ограничение в 10 штук связанно с ограничениями API VK
			for ($i=0; $i < $count_attachments; $i+=10) { 
				$attachments = $this->_make_attachements(array_splice($photos, 0, 10));
				$message = $this->_make_message(array_slice($goods_for_vk, $i, 10), $type);

				// Опубликовать запись на стене
				$resp_post = $this->VK->api('wall.post', array(
					'owner_id' => "-".$this->GROUP_ID,
					'from_group' => 1,
					'message' => $message,
					'attachments' => $attachments
				));
			}
			foreach ($goods_for_vk as $key => $value) {
				if ($type == $this->NEW) {
					$this->tovar->set_vk_status($value['kodt'], "wasPubVkNew");
				} else if ($type == $this->BESTPRICE) {
					$this->tovar->set_vk_status($value['kodt'], "wasPubVkBestPrice");
				} else if ($type == $this->HITS) {
					$this->tovar->set_vk_status($value['kodt'], "wasPubVkHit");
				}
			}
		} catch (VKException $vke) {
			echo "Error occure";
		}
		echo "VK wall posting ended";
	}

	
	private function _get_products($type)
	{
		$currshop=preg_replace('/[^0-9]/', '', $this->input->cookie('mag'));
		if ($type == $this->NEW) {
			$new_goods = $this->tovar->get_newtovarscena_vk($currshop);
		} else if ($type == $this->BESTPRICE) {
			$new_goods = $this->tovar->get_besttovarscena_vk($currshop);
		} else if ($type == $this->HITS) {
			$new_goods = $this->tovar->get_hittovarscena_vk($currshop);
		}
		$result = array();
		foreach ($new_goods as $key => $value) {
			if ($value->pict && file_exists(FCPATH.'images/items/cache_wm/'.$value->pict)) {
				$result[] = array('kodt' => $value->kodt,
								  'img' => FCPATH.'images/items/cache_wm/'.$value->pict, 
								  'name' => $value->nasv,
								  'cena' => $value->cena,
								  'alias' => $value->alias);
			} 
			// ограничение связанное с требованиями заказчика
			if (count($result) > 2) {
				break;
			}
		}   
		echo '<pre><b>Товары для публикации</b> ' . var_export($result, true) . '</pre>';
	
		return $result;
	}


	public function _send_picture($photos, $url)
	{
		$data = array();
		foreach ($photos as $key => $value) {
			try {
				if ( !file_exists($value['img']) ) {
					throw new Exception("Picture not found");
				}
				$img_src = $value['img'];
				
				if (version_compare(phpversion(), '5.6.10', '<')) {
					$data['file'.$key] = '@'.$img_src;
				} else {
					$data['file'.$key] = new CurlFile($img_src);
				}

			} catch (Exception $e) {
				echo $e;
			}
		}
		$ch = curl_init();
		$headers = array("Content-Type: multipart/form-data");
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_INFILESIZE, filesize($img_src));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		$resp = curl_exec( $ch );
		curl_close( $ch );
		unset($ch);
		unset($headers);
		return $resp_photo_load=json_decode($resp);
	}


	/**
	 * Данная функция создаёт строку вида 'ВидВладелец_Идентификатор,ВидВладелец_Идентификатор'
	 * @param  array $photos 
	 * @return string
	 */
	private function _make_attachements($photos)
	{
		$attach_string = '';
		// echo '<pre>' . var_export($photos, true) . '</pre>';
		foreach ($photos as $key => $value) {
			$attach_string .= sprintf("photo%s_%s,", $value['owner_id'], $value['id']);
		}
		echo $attach_string;
		return rtrim($attach_string, ","); 
	}


	/**
	 * Данная функция генерирует сообщение, которое будет опубликовано в посте
	 * @param  array $goods массив товаров
	 * @param  str $type  эта строка, которая может быть в $NEW, $BESTPRICE, $HITS полях класса
	 * @return str 
	 */
	private function _make_message($goods, $type)
	{
		if ($type == $this->NEW) {
			$message = "&#128295; &#128296; &#10071; &#128077; У нас новое поступление:\n\n";		
		} else if ($type == $this->BESTPRICE) {
			$message = "&#128201; &#128176; &#8252; &#128077; Лучшие цены, спешите сэкономить:\n\n";
		} else if ($type == $this->HITS) {
			$message = "&#128285; &#128293; &#11088; &#128077; Наши хиты продаж:\n\n";
		}
		foreach ($goods as $good) {
			$message .= "&#10133; ".html_entity_decode($good['name'])."\n" . $good['cena']."руб. \n" .base_url('catalog/item')."/".$good['alias']." \n \n";
		}
		// echo '<pre>' . var_export($message, true) . '</pre>';
		return $message;
	}


	private function _getWallUploadServer($group_id)
	{
		$resp = $this->VK->api('photos.getWallUploadServer', array(
			'group_id' => $this->GROUP_ID
		));
		return $resp['response']['upload_url'];
	}
}