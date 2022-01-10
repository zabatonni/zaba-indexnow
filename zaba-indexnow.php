<?php
/*Plugin Name: Zaba IndexNow*/

namespace ZABA\indexnow;

defined('ABSPATH') || exit;

if(!defined('ZABA_INDEXNOW_KEY')) define('ZABA_INDEXNOW_KEY',null);
if(!defined('ZABA_INDEXNOW_THROTTLE')) define('ZABA_INDEXNOW_THROTTLE',600);

define('ZABA_INDEXNOW_ENDPOINT','https://api.indexnow.org/indexnow');

new core();
class core {
	public function __construct() {
		register_activation_hook(__FILE__,function() {
			if(!ZABA_INDEXNOW_KEY) {
				wp_die('Set constant in your wp-config.php define(\'ZABA_INDEXNOW_KEY\',\''.wp_generate_password(32,false,false).'\'); before activating plugin',null,['back_link'=>true]);
				exit;
			}

			if(!wp_next_scheduled('zaba_indexnow_queue')) wp_schedule_event(time(),'zaba_indexnow_queue_interval','zaba_indexnow_queue');

			$this->rewrite_rule();

			flush_rewrite_rules(false);
		});

		register_deactivation_hook(__FILE__,function() {
			wp_clear_scheduled_hook('zaba_indexnow_queue');

			flush_rewrite_rules(false);
		});

		if(!ZABA_INDEXNOW_KEY) return;

		add_filter('cron_schedules',function($cron) {
			$cron['zaba_indexnow_queue_interval']=['interval'=>60];

			return $cron;
		},10,1);

		add_action('init',[$this,'rewrite_rule'],10);

		add_filter('query_vars',function($vars) {
			$vars[]='indexnow';

			return $vars;
		},10,1);

		add_filter('redirect_canonical',function($redirect) {
			if(get_query_var('indexnow')) return false;

			return $redirect;
		},10,1);

		add_action('template_include',function($template) {
			if(!get_query_var('indexnow')) return $template;

			header('Content-type: text/plain; charset=utf-8');
			header('X-Robots-Tag: noindex');

			exit(ZABA_INDEXNOW_KEY);
		},10,1);

		add_action('transition_post_status',function($new,$old,$post) {
			if($new === 'auto-draft' || !is_post_type_viewable($post->post_type)) return;

			if($old !== 'publish' && $new !== 'publish') return;

			if($new !== 'publish') {
				$tmp=$post;
				$tmp->post_status='publish';

				$url=preg_replace('%(.*)__trashed/$%','$1/',get_permalink($tmp));
			}
			else {
				$url=get_permalink($post);
			}

			$this->process($url);
		},10,3);

		add_action('comment_post',function($commentID,$approved,$data) {
			if($approved !== 1 || empty($data->comment_post_ID)) return;

			if(get_post_status($data->comment_post_ID) !== 'publish') return;

			$this->process(get_permalink($data->comment_post_ID));
		},10,3);

		add_action('transition_comment_status',function($new,$old,$data) {
			if($new === $old || $new !== 'approved' || empty($data->comment_post_ID)) return;

			if(get_post_status($data->comment_post_ID) !== 'publish') return;

			$this->process(get_permalink($data->comment_post_ID));
		},10,3);

		add_action('zaba_indexnow_queue',function() {
			if($this->get_locked()) return;

			$urls=$this->get_queue();

			if(empty($urls)) return;

			$chunk=array_slice($urls,0,10000);

			update_option('zaba_indexnow_queue',array_values(array_diff($urls,$chunk)),false);

			$this->ping($urls);
		},10);
	}

	public function rewrite_rule() {
		add_rewrite_rule('^'.ZABA_INDEXNOW_KEY.'\.txt$','index.php?indexnow=1','top');
	}

	private function process($url) {
		if(empty($url)) return;

		($this->get_locked()) ? $this->add_queue($url) : $this->ping($url);
	}

	private function get_locked() {
		return (ZABA_INDEXNOW_THROTTLE) ? get_transient('zaba_indexnow_throttle') : false;
	}

	private function get_queue() {
		return get_option('zaba_indexnow_queue') ?: [];
	}

	private function add_queue($url) {
		if(!$url) return;

		$prev=$this->get_queue();

		if(in_array($url,$prev)) return;

		update_option('zaba_indexnow_queue',[...$prev,$url],false);
	}

	private function ping($url) {
		if(empty($url)) return;

		if(is_array($url)) {
			wp_remote_post(
				ZABA_INDEXNOW_ENDPOINT,
				[
					'body'=>json_encode([
						'host'=>!empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : parse_url(home_url('/'),PHP_URL_HOST),
						'urlList'=>$url,
						'key'=>ZABA_INDEXNOW_KEY
					]),
					'headers'=>['Content-Type'=>'application/json; charset=utf-8']
				]
			);
		}
		else {
			wp_remote_get(
				add_query_arg(
					['url'=>urlencode($url),'key'=>ZABA_INDEXNOW_KEY],
					ZABA_INDEXNOW_ENDPOINT
				)
			);
		}

		if(ZABA_INDEXNOW_THROTTLE) set_transient('zaba_indexnow_throttle',1,ZABA_INDEXNOW_THROTTLE);
	}
}