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

			$this->rewrite_rule();

			flush_rewrite_rules(false);
		});

		register_deactivation_hook(__FILE__,function() {
			$posts=get_posts([
				'posts_per_page'=>-1,
				'post_type'=>'any',
				'post_status'=>'any',
				'fields'=>'ids'
			]);

			foreach ($posts as $postID) {
				delete_post_meta($postID,'_zaba_indexnow');
			}

			flush_rewrite_rules(false);
		});

		if(!ZABA_INDEXNOW_KEY) return;

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

			$this->process($post->ID,$url);
		},10,3);

		add_action('comment_post',function($commentID,$approved,$data) {
			if($approved !== 1 || empty($data->comment_post_ID)) return;

			if(get_post_status($data->comment_post_ID) !== 'publish') return;

			$this->process($data->comment_post_ID,get_permalink($data->comment_post_ID));
		},10,3);

		add_action('transition_comment_status',function($new,$old,$data) {
			if($new === $old || $new !== 'approved' || empty($data->comment_post_ID)) return;

			if(get_post_status($data->comment_post_ID) !== 'publish') return;

			$this->process($data->comment_post_ID,get_permalink($data->comment_post_ID));
		},10,3);

		add_action('zaba_indexnow_ping',function($id,$url) {
			$this->ping($id,$url);
		},10,2);
	}

	public function rewrite_rule() {
		add_rewrite_rule('^'.ZABA_INDEXNOW_KEY.'\.txt$','index.php?indexnow=1','top');
	}

	private function process($id,$url) {
		if(empty($id) || empty($url)) return;

		if(ZABA_INDEXNOW_THROTTLE) {
			$last=(int)get_post_meta($id,'_zaba_indexnow',true);

			if($last && time() < $last + ZABA_INDEXNOW_THROTTLE) {
				if(wp_next_scheduled('zaba_indexnow_ping',['id'=>$id,'url'=>$url])) return;

				wp_schedule_single_event($last + ZABA_INDEXNOW_THROTTLE,'zaba_indexnow_ping',['id'=>$id,'url'=>$url]);

				return;
			}
		}

		$this->ping($id,$url);
	}

	private function ping($id,$url) {
		if(empty($id) || empty($url)) return;

		wp_remote_get(
			add_query_arg(
				['url'=>urlencode($url),'key'=>ZABA_INDEXNOW_KEY],
				ZABA_INDEXNOW_ENDPOINT
			)
		);

		if(ZABA_INDEXNOW_THROTTLE) update_post_meta($id,'_zaba_indexnow',time());
	}
}