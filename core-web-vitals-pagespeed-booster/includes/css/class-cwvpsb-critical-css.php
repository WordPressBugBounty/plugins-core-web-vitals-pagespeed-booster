<?php
/**
 * Critical CSS functionality 
 * @since 1.3
 * 
 **/

class CWVPSB_Critical_Css
{


	public function __construct()
	{
		$this->init();
	}

	public function cachepath()
	{
		if (defined(CWVPSB_CRITICAL_CSS_CACHE_DIR)) {
			return CWVPSB_CRITICAL_CSS_CACHE_DIR;
		} else {
			return WP_CONTENT_DIR . "/cache/cwvpsb/css/";
		}
	}

	public function init()
	{

		if (function_exists('is_checkout') && is_checkout() || (function_exists('is_feed') && is_feed())) {
			return;
		}
		if (function_exists('elementor_load_plugin_textdomain') && \Elementor\Plugin::$instance->preview->is_preview_mode()) {
			return;
		}
		add_action('admin_notices', array($this, 'cwvpsb_add_admin_notices'));
		if (function_exists('is_user_logged_in') && !is_user_logged_in()) {
			add_action('wp', array($this, 'delay_css_loadings'), 999);
		}

		add_action('create_term', function ($term_id, $tt_id, $taxonomy) {
			$this->on_term_create($term_id, $tt_id, $taxonomy);
		}, 10, 3);

		add_action('save_post', function ($post_ID, $post, $update) {
			$this->on_post_change($post_ID, $post);
		}, 10, 3);
		add_action('wp_insert_post', function ($post_ID, $post, $update) {
			$this->on_post_change($post_ID, $post);
		}, 10, 3);

		add_action('wp_head', array($this, 'print_style_cc'), 2);

		add_action("wp_ajax_cwvpsb_resend_urls_for_cache", array($this, 'cwvpsb_resend_urls_for_cache'));
		add_action("wp_ajax_cwvpsb_resend_single_url_for_cache", array($this, 'cwvpsb_resend_single_url_for_cache'));
		add_action("wp_ajax_cwvpsb_reset_urls_cache", array($this, 'cwvpsb_reset_urls_cache'));
		add_action("wp_ajax_cwvpsb_recheck_urls_cache", array($this, 'cwvpsb_recheck_urls_cache'));
		add_action("wp_ajax_cwvpsb_cc_all_cron", array($this, 'every_one_minutes_event_func'));

		add_filter('cron_schedules', array($this, 'isa_add_every_one_hour'));
		if (!wp_next_scheduled('isa_add_every_one_hour')) {
			wp_schedule_event(time(), 'every_one_hour', 'isa_add_every_one_hour');
		}
		add_action('isa_add_every_one_hour', array($this, 'every_one_minutes_event_func'));

		if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON == true) {
			//add_action( 'admin_init', array($this, 'every_one_minutes_event_func' ) );	
			add_action('current_screen', array($this, 'cwvpsb_custom_critical_css_generate'));
		}
	}

	public function cwvpsb_custom_critical_css_generate()
	{
		//date("i",time())%5;
		if (is_admin()) {
			$current_screen = get_current_screen();
			if (isset($current_screen->id) && $current_screen->id == 'toplevel_page_cwvpsb') {
				$this->every_one_minutes_event_func();
			}
		}
	}

	function print_style_cc()
	{
		$user_dirname = $this->cachepath();
		global $wp, $wpdb, $table_prefix;
		$table_name = $table_prefix . 'cwvpb_critical_urls';
		$url = home_url($wp->request);
		$url = trailingslashit($url);
		if (file_exists($user_dirname . md5($url) . '.css')) {
			$css_safe = cwvpsb_read_file_contents($user_dirname . '/' . md5($url) . '.css');
			echo "<style type='text/css' id='cc-styles'>{$css_safe}</style>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			$wpdb->query($wpdb->prepare( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching  --Reason: Direct DB call to update table
				"UPDATE $table_name SET `status` = %s,  `cached_name` = %s WHERE `url` = %s", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared	
				'queue',
				'',
				$url
			));
		}
	}

	public function delay_css_loadings()
	{
		if (function_exists('is_checkout') && is_checkout() || (function_exists('is_feed') && is_feed())) {
			return;
		}
		if (function_exists('elementor_load_plugin_textdomain') && \Elementor\Plugin::$instance->preview->is_preview_mode()) {
			return;
		}
		add_filter('cwvpsb_complete_html_after_dom_loaded', array($this, 'cwvpsb_delay_css_html'), 1, 1);
	}

	function check_critical_css($url = '')
	{
		$user_dirname = $this->cachepath();
		if (!$url) {
			global $wp;
			$url = home_url($wp->request);
		}
		$url = trailingslashit($url);
		return file_exists($user_dirname . md5($url) . '.css') ? true : false;
	}

	public function cwvpsb_delay_css_html($html)
	{
		$return_html = $jetpack_boost = false;
		if (!$this->check_critical_css()) {
			$return_html = true;
		}

		if (preg_match('/<style id="jetpack-boost-critical-css">/s', $html)) {
			$return_html = false;
			$jetpack_boost = true;
		}

		if ($return_html == true) {
			return $html;
		}
		$html_no_comments = preg_replace('/<!--(.*)-->/Uis', '', $html);
		preg_match_all('/<link\s?([^>]+)?>/is', $html_no_comments, $matches);

		if (!isset($matches[0])) {
			return $html;
		}

		foreach ($matches[0] as $i => $tag) {
			$atts_array = !empty($matches[1][$i]) ? $this->cwvpsb_get_atts_array($matches[1][$i]) : array();
			if (isset($atts_array['rel']) && stripos($atts_array['rel'], 'stylesheet') === false) {
				continue;
			}
			$delay_flag = false;
			$excluded_scripts = array(
				'cwvpsb-delayed-styles',
			);

			if (!empty($excluded_scripts) && is_array($excluded_scripts)) {
				foreach ($excluded_scripts as $excluded_script) {
					if (strpos($tag, $excluded_script) !== false) {
						continue 2;
					}
				}
			}

			$delay_flag = true;
			if (!empty($atts_array['rel'])) {
				$atts_array['data-cwvpsb-rel'] = $atts_array['rel'];
			}

			$atts_array['rel'] = 'cwvpsbdelayedstyle';

			if (!empty($atts_array['href'])) {
				$atts_array['defer'] = 'defer';
			}


			if ($delay_flag) {
				$delayed_atts_string = $this->cwvpsb_get_atts_string($atts_array);
				$delayed_tag = sprintf('<link %1$s', $delayed_atts_string) . (!empty($matches[3][$i]) ? $matches[3][$i] : '') . '/>';
				$html = str_replace($tag, $delayed_tag, $html);
				continue;
			}
		}

		preg_match_all('#(<style\s?([^>]+)?\/?>)(.*?)<\/style>#is', $html_no_comments, $matches1);
		if (isset($matches1[0]) && is_array($matches1[0])) {
			foreach ($matches1[0] as $i => $tag) {
				$atts_array = !empty($matches1[2][$i]) ? $this->cwvpsb_get_atts_array($matches1[2][$i]) : array();
				if (isset($atts_array['id']) && $atts_array['id'] == 'cc-styles') {
					continue;
				}
				if (isset($atts_array['type'])) {
					$atts_array['data-cwvpsb-cc-type'] = $atts_array['type'];
				}
				$delayed_atts_string = $this->cwvpsb_get_atts_string($atts_array);
				$delayed_tag = sprintf('<style %1$s>', $delayed_atts_string) . (!empty($matches1[3][$i]) ? $matches1[3][$i] : '') . '</style>';
				$html = str_replace($tag, $delayed_tag, $html);
			}
		}

		if ($jetpack_boost == true && preg_match('/<style\s+id="jetpack-boost-critical-css"\s+type="cwvpsbdelayedstyle">/s', $html)) {
			$html = preg_replace('/<style\s+id="jetpack-boost-critical-css"\s+type="cwvpsbdelayedstyle">/s', '<style id="jetpack-boost-critical-css">', $html);
		}
		return $html;
	}

	function cwvpsb_get_atts_string($atts_array)
	{

		if (!empty($atts_array)) {
			$assigned_atts_array = array_map(
				function ($name, $value) {
					if ($value === '') {
						return $name;
					}
					return sprintf('%s="%s"', $name, esc_attr($value));
				},
				array_keys($atts_array),
				$atts_array
			);
			$atts_string = implode(' ', $assigned_atts_array);
			return $atts_string;
		}
		return false;
	}

	function cwvpsb_get_atts_array($atts_string)
	{

		if (!empty($atts_string)) {
			$atts_array = array_map(
				function (array $attribute) {
					return $attribute['value'];
				},
				wp_kses_hair($atts_string, wp_allowed_protocols())
			);
			return $atts_array;
		}
		return false;
	}


	function cwvpsb_delay_js_load()
	{
		if (!$this->check_critical_css()) {
			return;
		}
		$settings = cwvpsb_defaults();
		if ((cwvpsb_is_mobile() && $settings['delay_js_mobile'] == 'php') || $settings['delay_js'] == 'php') {
			return;
		}
		echo '<script  id="cwvpsb-delayed-styles">
			cwvpsbUserInteractions = ["keydown", "mousemove", "wheel", "touchmove", "touchstart", "touchend", "touchcancel", "touchforcechange"], cwvpsbDelayedScripts = {
		    normal: [],
		    defer: [],
		    async: []
		}, jQueriesArray = [];
		var cwvpsbDOMLoaded = !1;

		function cwvpsbTriggerDOMListener() {
		    ' . '
		    cwvpsbUserInteractions.forEach(function(e) {
		        window.removeEventListener(e, cwvpsbTriggerDOMListener, {
		            passive: !0
		        })
		    }), "loading" === document.readyState ? document.addEventListener("DOMContentLoaded", cwvpsbTriggerDelayedScripts) : cwvpsbTriggerDelayedScripts()
		}
		async function cwvpsbTriggerDelayedScripts() {
		    cwvpsbDelayEventListeners(), cwvpsbDelayJQueryReady(), cwvpsbProcessDocumentWrite(), cwvpsbSortDelayedScripts(), cwvpsbPreloadDelayedScripts(), await cwvpsbLoadDelayedScripts(cwvpsbDelayedScripts.normal), await cwvpsbLoadDelayedScripts(cwvpsbDelayedScripts.defer), await cwvpsbLoadDelayedScripts(cwvpsbDelayedScripts.async), await cwvpsbTriggerEventListeners(), ctl()
		}

		function cwvpsbDelayEventListeners() {
		    let e = {};

		    function t(t, n) {
		        function r(n) {
		            return e[t].delayedEvents.indexOf(n) >= 0 ? "cwvpsb-" + n : n
		        }
		        e[t] || (e[t] = {
		            originalFunctions: {
		                add: t.addEventListener,
		                remove: t.removeEventListener
		            },
		            delayedEvents: []
		        }, t.addEventListener = function() {
		            arguments[0] = r(arguments[0]), e[t].originalFunctions.add.apply(t, arguments)
		        }, t.removeEventListener = function() {
		            arguments[0] = r(arguments[0]), e[t].originalFunctions.remove.apply(t, arguments)
		        }), e[t].delayedEvents.push(n)
		    }

		    function n(e, t) {
		        const n = e[t];
		        Object.defineProperty(e, t, {
		            get: n || function() {},
		            set: function(n) {
		                e["cwvpsb" + t] = n
		            }
		        })
		    }
		    t(document, "DOMContentLoaded"), t(window, "DOMContentLoaded"), t(window, "load"), t(window, "pageshow"), t(document, "readystatechange"), n(document, "onreadystatechange"), n(window, "onload"), n(window, "onpageshow")
		}

		function cwvpsbDelayJQueryReady() {
		    let e = window.jQuery;
		    Object.defineProperty(window, "jQuery", {
		        get: () => e,
		        set(t) {
		            if (t && t.fn && !jQueriesArray.includes(t)) {
		                t.fn.ready = t.fn.init.prototype.ready = function(e) {
		                    cwvpsbDOMLoaded ? e.bind(document)(t) : document.addEventListener("cwvpsb-DOMContentLoaded", function() {
		                        e.bind(document)(t)
		                    })
		                };
		                const e = t.fn.on;
		                t.fn.on = t.fn.init.prototype.on = function() {
		                    if (this[0] === window) {
		                        function t(e) {
		                            return e.split(" ").map(e => "load" === e || 0 === e.indexOf("load.") ? "cwvpsb-jquery-load" : e).join(" ")
		                        }
		                        "string" == typeof arguments[0] || arguments[0] instanceof String ? arguments[0] = t(arguments[0]) : "object" == typeof arguments[0] && Object.keys(arguments[0]).forEach(function(e) {
		                            delete Object.assign(arguments[0], {
		                                [t(e)]: arguments[0][e]
		                            })[e]
		                        })
		                    }
		                    return e.apply(this, arguments), this
		                }, jQueriesArray.push(t)
		            }
		            e = t
		        }
		    })
		}

		function cwvpsbProcessDocumentWrite() {
		    const e = new Map;
		    document.write = document.writeln = function(t) {
		        var n = document.currentScript,
		            r = document.createRange();
		        let a = e.get(n);
		        void 0 === a && (a = n.nextSibling, e.set(n, a));
		        var o = document.createDocumentFragment();
		        r.setStart(o, 0), o.appendChild(r.createContextualFragment(t)), n.parentElement.insertBefore(o, a)
		    }
		}

		function cwvpsbSortDelayedScripts() {
		    document.querySelectorAll("script[type=cwvpsbdelayedscript]").forEach(function(e) {
		        e.hasAttribute("src") ? e.hasAttribute("defer") && !1 !== e.defer ? cwvpsbDelayedScripts.defer.push(e) : e.hasAttribute("async") && !1 !== e.async ? cwvpsbDelayedScripts.async.push(e) : cwvpsbDelayedScripts.normal.push(e) : cwvpsbDelayedScripts.normal.push(e)
		    })
		}

		function cwvpsbPreloadDelayedScripts() {
		    var e = document.createDocumentFragment();
		    [...cwvpsbDelayedScripts.normal, ...cwvpsbDelayedScripts.defer, ...cwvpsbDelayedScripts.async].forEach(function(t) {
		        var n = t.getAttribute("src");
		        if (n) {
		            var r = document.createElement("link");
		            r.href = n, r.rel = "preload", r.as = "script", e.appendChild(r)
		        }
		    }), document.head.appendChild(e)
		}
		async function cwvpsbLoadDelayedScripts(e) {
		    var t = e.shift();
		    return t ? (await cwvpsbReplaceScript(t), cwvpsbLoadDelayedScripts(e)) : Promise.resolve()
		}
		async function cwvpsbReplaceScript(e) {
		    return await cwvpsbNextFrame(), new Promise(function(t) {
		        const n = document.createElement("script");
		        [...e.attributes].forEach(function(e) {
		            let t = e.nodeName;
		            "type" !== t && ("data-type" === t && (t = "type"), n.setAttribute(t, e.nodeValue))
		        }), e.hasAttribute("src") ? (n.addEventListener("load", t), n.addEventListener("error", t)) : (n.text = e.text, t()), e.parentNode.replaceChild(n, e)
		    })
		}
		function ctl(){
						var cssEle = document.querySelectorAll("link[rel=cwvpsbdelayedstyle]");
						for(var i=0; i <= cssEle.length;i++){
							if(cssEle[i]){
								var cssMain = document.createElement("link");
								cssMain.href = cssEle[i].href;
								cssMain.rel = "stylesheet";
								cssMain.type = "text/css";
								document.getElementsByTagName("head")[0].appendChild(cssMain);
							}
						}
						var cssEle = document.querySelectorAll("style[type=cwvpsbdelayedstyle]");
						for(var i=0; i <= cssEle.length;i++){
							if(cssEle[i]){
								var cssMain = document.createElement("style");
								cssMain.type = "text/css";
								/*cssMain.rel = "stylesheet";*/
								/*cssMain.type = "text/css";*/
								cssMain.textContent = cssEle[i].textContent;
								document.getElementsByTagName("head")[0].appendChild(cssMain);
							}
						}
					}
		async function cwvpsbTriggerEventListeners() {
		    cwvpsbDOMLoaded = !0, await cwvpsbNextFrame(), document.dispatchEvent(new Event("cwvpsb-DOMContentLoaded")), await cwvpsbNextFrame(), window.dispatchEvent(new Event("cwvpsb-DOMContentLoaded")), await cwvpsbNextFrame(), document.dispatchEvent(new Event("cwvpsb-readystatechange")), await cwvpsbNextFrame(), document.cwvpsbonreadystatechange && document.cwvpsbonreadystatechange(), await cwvpsbNextFrame(), window.dispatchEvent(new Event("cwvpsb-load")), await cwvpsbNextFrame(), window.cwvpsbonload && window.cwvpsbonload(), await cwvpsbNextFrame(), jQueriesArray.forEach(function(e) {
		        e(window).trigger("cwvpsb-jquery-load")
		    }), window.dispatchEvent(new Event("cwvpsb-pageshow")), await cwvpsbNextFrame(), window.cwvpsbonpageshow && window.cwvpsbonpageshow()
		}
		async function cwvpsbNextFrame() {
		    return new Promise(function(e) {
		        requestAnimationFrame(e)
		    })
		}
		cwvpsbUserInteractions.forEach(function(e) {
		    window.addEventListener(e,  {
		        passive: !0
		    })
		});


		</script>';
	}

	function isa_add_every_one_hour($schedules)
	{
		$schedules['every_one_hour'] = array(
			'interval' => 8 * 1,
			'display' => __('Every 8 Seconds', 'cwvpsb')
		);
		return $schedules;
	}

	public function insert_update_posts_url($post_id)
	{

		global $wpdb, $table_prefix;
		$table_name = $table_prefix . 'cwvpb_critical_urls';

		$permalink = get_permalink($post_id);
		if (!empty($permalink)) {

			$permalink = $this->append_slash_permalink($permalink);

			$pid = $wpdb->get_var( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT `url` FROM $table_name WHERE `url`=%s limit %d", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$permalink,
					1
				)
			);

			if (is_null($pid)) {
				$wpdb->insert( //phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table_name,
					array(
						'url_id' => $post_id,
						'type' => get_post_type($post_id),
						'type_name' => get_post_type($post_id),
						'url' => $permalink,
						'status' => 'queue',
						'created_at' => gmdate('Y-m-d h:i:sa'),
					),
					array('%d', '%s', '%s', '%s', '%s', '%s')
				);

			}
		}

	}

	public function insert_update_terms_url($term)
	{
		if (!is_object($term)) {
			return;
		}
		global $wpdb, $table_prefix;
		$table_name_escaped = esc_sql( $table_prefix . 'cwvpb_critical_urls');
		$permalink = get_term_link($term);

		if (!empty($permalink)) {

			$permalink = $this->append_slash_permalink($permalink);

			$pid = $wpdb->get_var( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT `url` FROM {$table_name_escaped} WHERE `url`=%s limit 1", //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$permalink
				)
			);

			if (is_null($pid)) {
				$wpdb->insert(  //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$table_name_escaped,
					array(
						'url_id' => $term->term_id,
						'type' => $term->taxonomy,
						'type_name' => $term->taxonomy,
						'url' => $permalink,
						'status' => 'queue',
						'created_at' => gmdate('Y-m-d h:i:sa')
					),
					array('%d', '%s', '%s', '%s', '%s', '%s')
				);

			}

		}

	}

	public function save_posts_url()
	{

		global $wpdb, $table_prefix;
		$table_name = $table_prefix . 'cwvpb_critical_urls';

		$settings = cwvpsb_defaults();

		$post_types = array();

		if (!empty($settings['critical_css_on_cp_type'])) {
			foreach ($settings['critical_css_on_cp_type'] as $key => $value) {
				if ($value) {
					$post_types[] = $key;
				}
			}
		}

		$start = get_option('save_posts_offset') ? get_option('save_posts_offset') : 0;
		$batch = 30;
		$offset = $start * $batch;
		$posts = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching  --Reason: Direct DB call to get posts
			$wpdb->prepare( 
				"SELECT `ID` FROM {$wpdb->posts} WHERE post_status=%s 
					AND post_type IN(%s) LIMIT %d, %d",
				'publish',
				implode("', '", $post_types),
				$offset,
				$batch
			)
			,
			ARRAY_A
		);

		if (!empty($posts)) {
			$start = $start + 1;
			foreach ($posts as $post) {
				$this->insert_update_posts_url($post['ID']);
			}
		} else {
			$start = 0;
		}
		update_option('save_posts_offset', $start);

	}

	public function save_others_urls()
	{

		global $wpdb, $table_prefix;
		$table_name = $table_prefix . 'cwvpb_critical_urls';

		$settings = cwvpsb_defaults();
		$urls_to = array();
		if (isset($settings['critical_css_on_home']) && $settings['critical_css_on_home'] == 1) {
			$urls_to[] = get_home_url(); //always purge home page if any other page is modified
			$urls_to[] = get_home_url() . "/"; //always purge home page if any other page is modified
			$urls_to[] = home_url('/'); //always purge home page if any other page is modified
			$urls_to[] = site_url('/'); //always purge home page if any other page is modified
		}

		if (!empty($urls_to)) {

			foreach ($urls_to as $key => $value) {

				$pid = $wpdb->get_var( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT `url` FROM $table_name WHERE `url`=%s limit %d", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared  --Reason: Direct DB call to get posts
						$value,
						1
					)
				);
				$id = ($key++) + 999999999;
				if (is_null($pid)) {

					$wpdb->insert( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
						$table_name,
						array(
							'url_id' => $id,
							'type' => 'others',
							'type_name' => 'others',
							'url' => $value,
							'status' => 'queue',
							'created_at' => gmdate('Y-m-d'),
						),
						array('%d', '%s', '%s', '%s', '%s', '%s')
					);

				} else {
					$wpdb->query($wpdb->prepare( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						"UPDATE $table_name SET `url` = %s WHERE `url_id` = %d", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared  --Reason: Direct DB call to update table
						$value,
						$id
					));

				}

			}
		}


	}



	public function save_terms_urls()
	{

		global $wpdb, $table_prefix;
		$table_name = $table_prefix . 'cwvpb_critical_urls';

		$settings = cwvpsb_defaults();

		$taxonomy_types = array();

		if (!empty($settings['critical_css_on_tax_type'])) {
			foreach ($settings['critical_css_on_tax_type'] as $key => $value) {
				if ($value) {
					$taxonomy_types[] = $key;
				}
			}
		}
		$start = get_option('save_terms_offset') ? get_option('save_terms_offset') : 0;
		$batch = 30;
		$offset = $start * $batch;
		$terms = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT `term_id`, `taxonomy` FROM {$wpdb->term_taxonomy}
					WHERE taxonomy IN(%s) LIMIT %d, %d",
				implode("', '", $taxonomy_types),
				$offset,
				$batch
			)
			,
			ARRAY_A
		);

		if (!empty($terms)) {
			$start = $start + 1;
			foreach ($terms as $term) {
				$term = get_term($term['term_id']);
				if (!is_wp_error($term)) {
					$this->insert_update_terms_url($term);
				}
			}
		} else {
			$start = 0;
		}
		update_option('save_terms_offset', $start);

	}

	public function generate_css_on_interval()
	{

		global $wpdb;
		$table_name_escaped = esc_sql($wpdb->prefix . 'cwvpb_critical_urls');

		$result = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table_name_escaped} WHERE `status` IN  (%s) LIMIT %d", //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared	 --Reason: Direct DB call to get posts
				'queue',
				4
			)
			,
			ARRAY_A
		);

		if (!empty($result)) {

			global $wp_filesystem;

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();

			

			$user_dirname = $this->cachepath();
			if (  ! $wp_filesystem->is_dir( $user_dirname ) ) {
				$wp_filesystem->mkdir( $user_dirname );
				if($wp_filesystem->is_dir( $user_dirname )){
					$wp_filesystem->chmod( $user_dirname, 755 );
				}
			}

			if (is_dir($user_dirname)) {

				foreach ($result as $value) {

					if ($value['url']) {
						$status = 'inprocess';
						$cached_name = '';
						$failed_error = '';
						$this->change_caching_status($value['url'], $status);
						$result = $this->cwvpsb_save_critical_css_in_dir_php($value['url']);

						if ($result['status']) {
							$status = 'cached';
							$cached_name = md5($value['url']);
						} else {
							$status = 'failed';
							$failed_error = $result['message'];
						}

						$this->change_caching_status($value['url'], $status, $cached_name, $failed_error);
					}

				}
			}

		}

	}

	public function change_caching_status($url, $status, $cached_name = null, $failed_error = null)
	{

		global $wpdb, $table_prefix;
		$table_name = $table_prefix . 'cwvpb_critical_urls';

		$result = $wpdb->query($wpdb->prepare( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"UPDATE {$table_name} SET `status` = %s,  `cached_name` = %s,  `updated_at` = %s,  `failed_error` = %s WHERE `url` = %s", //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared  --Reason: Direct DB call to update table
			$status,
			$cached_name,
			gmdate('Y-m-d h:i:sa'),
			$failed_error,
			$url
		));

	}

	public function every_one_minutes_event_func()
	{

		$this->save_posts_url();
		$this->save_terms_urls();
		$this->save_others_urls();
		$this->generate_css_on_interval();

	}

	public function append_slash_permalink($permalink)
	{

		$permalink_structure = get_option('permalink_structure');
		$append_slash = substr($permalink_structure, -1) == "/" ? true : false;
		if ($append_slash) {
			$permalink = trailingslashit($permalink);
		} else {
			$permalink = $permalink . $append_slash;
		}

		return $permalink;
	}

	public function on_term_create($term_id, $tt_id, $taxonomy)
	{

		$settings = cwvpsb_defaults();
		$post_types = array();
		if (!empty($settings['critical_css_on_tax_type'])) {
			foreach ($settings['critical_css_on_tax_type'] as $key => $value) {
				if ($value) {
					$post_types[] = $key;
				}
			}
		}

		if (in_array($taxonomy, $post_types)) {
			$term = get_term($term_id);
			if ($term) {
				$this->insert_update_terms_url($term);
			}
		}

		update_option('save_terms_offset', 0);

	}
	public function on_post_change($post_id, $post)
	{

		$settings = cwvpsb_defaults();
		$post_types = array();
		if (!empty($settings['critical_css_on_cp_type'])) {
			foreach ($settings['critical_css_on_cp_type'] as $key => $value) {
				if ($value) {
					$post_types[] = $key;
				}
			}
		}

		if (in_array($post->post_type, $post_types)) {
			$permalink = get_permalink($post_id);
			$permalink = $this->append_slash_permalink($permalink);
			if ($post->post_status == 'publish') {
				$this->insert_update_posts_url($post_id);
			}
		}
		update_option('save_posts_offset', 0);
	}


	public function cwvpsb_save_critical_css_in_dir_php($current_url)
	{

		$targetUrl = add_query_arg( 't', time(), $current_url ); // Add timestamp to avoid cache
		$user_dirname = $this->cachepath();
		$response = wp_remote_get($targetUrl);
		$content = wp_remote_retrieve_body($response);
		$regex1 = '/<link(.*?)href="(.*?)"(.*?)>/';
		preg_match_all($regex1, $content, $matches1, PREG_SET_ORDER);
		$regex2 = "/<link(.*?)href='(.*?)'(.*?)>/";
		preg_match_all($regex2, $content, $matches2, PREG_SET_ORDER);
		$matches = array_merge($matches1, $matches2);

		$rowcss = '';
		$all_css = [];

		if (!empty($matches)) {
			foreach ($matches as $mat) {
				if ((strpos($mat[2], '.css') !== false) && (strpos($mat[1], 'preload') === false)) {
					$all_css[] = $mat[2];
					$response2 = wp_remote_get($mat[2]);
					$rowcssdata = wp_remote_retrieve_body($response2);

					$regexn = '/@import\s*(url)?\s*\(?([^;]+?)\)?;/';

					preg_match_all($regexn, $rowcssdata, $matchen, PREG_SET_ORDER);

					if (!empty($matchen)) {
						foreach ($matchen as $matn) {
							if (isset($matn[2])) {
								$explod = explode('/', $matn[2]);
								if (is_array($explod)) {
									$style = trim(end($explod), '"');
									if (strpos($style, '.css') !== false) {
										$pthemestyle = get_template_directory_uri() . '/' . $style;
										$response3 = wp_remote_get($pthemestyle);
										$rowcss .= wp_remote_retrieve_body($response3);
									}
								}
							}
						}
					}
					$rowcss .= $rowcssdata;
				}

			}
		}

		if ($content) {
			$d = new DOMDocument;
			$mock = new DOMDocument;
			libxml_use_internal_errors(true);
			$decodedHtml = mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8');
			$d->loadHTML($decodedHtml);
			$body = $d->getElementsByTagName('body')->item(0);
			foreach ($body->childNodes as $child) {
				$mock->appendChild($mock->importNode($child, true));
			}

			$rawHtml = $mock->saveHTML();

			require_once CWVPSB_DIR . "/css-extractor/vendor/autoload.php";

			$extracted_css_arr = array();

			$page_specific = new \PageSpecificCss\PageSpecificCss();
			$page_specific_css = preg_replace("/@media[^{]*+{([^{}]++|{[^{}]*+})*+}/", '', $rowcss);
			$page_specific->addBaseRules($page_specific_css);
			$page_specific->addHtmlToStore($rawHtml);
			$extractedCss = $page_specific->buildExtractedRuleSet();
			$extracted_css_arr[] = $extractedCss;
		}

		preg_match_all("/@media[^{]*+{([^{}]++|{[^{}]*+})*+}/", $rowcss, $matchess, PREG_SET_ORDER);

		if ($matchess) {

			foreach ($matchess as $key => $value) {

				if (isset($value[0])) {
					$explod = explode("{", $value[0]);
					if ($explod[0]) {
						$value[0] = str_replace($explod[0] . "{", "", $value[0]);
						$value[0] = str_replace($explod[0] . " {", "", $value[0]);
						$value[0] = str_replace($explod[0] . "  {", "", $value[0]);
						$value[0] = substr($value[0], 0, -1);

						if ($value[0]) {
							$page_specific = new \PageSpecificCss\PageSpecificCss();
							$page_specific->addBaseRules($value[0]);
							$page_specific->addHtmlToStore($rawHtml);
							$extractedCss = $page_specific->buildExtractedRuleSet();
							if ($extractedCss) {
								$extractedCss = $explod[0] . "{" . $extractedCss . "}";
								$extracted_css_arr[] = $extractedCss;
							}
						}

					}
				}

			}
		}

		if (!empty($extracted_css_arr) && is_array($extracted_css_arr)) {

			$critical_css = implode("", $extracted_css_arr);
			$critical_css = str_replace("url('wp-content/", "url('" . get_site_url() . "/wp-content/", $critical_css);
			$critical_css = str_replace('url("wp-content/', 'url("' . get_site_url() . '/wp-content/', $critical_css);

			$new_file = $user_dirname . "/" . md5($current_url) . ".css";

			$response = cwvpsb_write_file_contents($new_file, $critical_css);

			if ( $response ) {
				return array('status' => true, 'message' => esc_html__('Critical CSS created successfully', 'cwvpsb'));
			} else {
				return array('status' => false, 'message' => esc_html__('Could not write into css file', 'cwvpsb'));
			}

		} else {
			return array('status' => false, 'message' => esc_html__('Critical css not generated from server', 'cwvpsb'));
		}


	}
	public function cwvpsb_resend_single_url_for_cache()
	{

		if (!isset($_POST['cwvpsb_security_nonce'])) {
			return;
		}
		if (!wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cwvpsb_security_nonce'] ) ) , 'cwvpsb_security_nonce')) {
			return;
		}

		if (!current_user_can('manage_options')) {
			return;
		}

		global $wpdb, $table_prefix;
		$table_name_escaped = esc_sql( $table_prefix . 'cwvpb_critical_urls');

		$url_id = isset( $_POST['url_id'] ) ? intval( sanitize_text_field( wp_unslash( $_POST['url_id'] ) ) ) : null;

		if ($url_id) {

			$result = $wpdb->query($wpdb->prepare( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				"UPDATE {$table_name_escaped} SET `status` = %s, `cached_name` = %s, `failed_error` = %s WHERE `id` = %d", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared	
				'queue',
				'',
				'',
				$url_id
			));


			if ($result) {
				wp_send_json(array('status' => true));
			} else {
				wp_send_json(array('status' => false));
			}

		} else {
			wp_send_json(array('status' => false));
		}

	}
	public function cwvpsb_resend_urls_for_cache()
	{

		if (!isset($_POST['cwvpsb_security_nonce'])) {
			return;
		}
		if (!wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['cwvpsb_security_nonce'] ) ), 'cwvpsb_security_nonce')) {
			return;
		}
		if (!current_user_can('manage_options')) {
			return;
		}

		global $wpdb, $table_prefix;
		$table_name_escaped = esc_sql( $table_prefix . 'cwvpb_critical_urls' );

		$result = $wpdb->query($wpdb->prepare( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"UPDATE {$table_name_escaped} SET `status` = %s, `cached_name` = %s, `failed_error` = %s WHERE `status` = %s", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared	
			'queue',
			'',
			'',
			'failed'
		));
		if ($result) {
			wp_send_json(array('status' => true));
		} else {
			wp_send_json(array('status' => false));
		}

	}
	public function cwvpsb_recheck_urls_cache()
	{

		if (!isset($_POST['cwvpsb_security_nonce'])) {
			return;
		}
		if (!wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['cwvpsb_security_nonce'] ) ), 'cwvpsb_security_nonce')) {
			return;
		}
		if (!current_user_can('manage_options')) {
			return;
		}
		$limit = 100;
		$page = isset( $_POST['page'] ) ? intval(sanitize_text_field( wp_unslash( $_POST['page'] ) ) ) : 0;
		$offset = $page * $limit;
		global $wpdb, $table_prefix;
		$table_name_escaped = esc_sql( $table_prefix . 'cwvpb_critical_urls' );

		$result = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table_name_escaped} WHERE `status` = %s LIMIT %d, %d", //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'cached',
				$offset,
				$limit
			)
			,
			ARRAY_A
		);

		if ($result && count($result) > 0) {
			$user_dirname = $this->cachepath();
			foreach ($result as $value) {

				if (!file_exists($user_dirname . $value['cached_name'] . '.css')) {
					$updated = $wpdb->query($wpdb->prepare( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						"UPDATE {$table_name_escaped} SET `status` = %s,  `cached_name` = %s WHERE `url` = %s", //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared	
						'queue',
						'',
						$value['url']
					));
				}
			}

			wp_send_json(array('status' => true, 'count' => count($result)));
		} else {
			wp_send_json(array('status' => true, 'count' => 0));
		}

	}
	public function cwvpsb_reset_urls_cache()
	{

		if (!isset($_POST['cwvpsb_security_nonce'])) {
			return;
		}
		if (!wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['cwvpsb_security_nonce'] ) ), 'cwvpsb_security_nonce')) {
			return;
		}

		if (!current_user_can('manage_options')) {
			return;
		}

		global $wpdb;
		$table_escaped = esc_sql( $wpdb->prefix . 'cwvpb_critical_urls' );
		$result = $wpdb->query("TRUNCATE TABLE {$table_escaped}"); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$dir = $this->cachepath();
		cwvpsb_remove_directory($dir);

		wp_send_json(array('status' => $result));

	}

	public function cwvpsb_showdetails_data()
	{


		if (!isset($_POST['cwvpsb_security_nonce'])) {
			return;
		}
		if ( ! wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['cwvpsb_security_nonce'] ) ), 'cwvpsb_security_nonce')) {
			return;
		}
		$start = isset($_POST['start']) ? intval(sanitize_text_field( wp_unslash( $_POST['start']) ) ) : 1;
		$length = isset($_POST['length']) ? intval(sanitize_text_field( wp_unslash( $_POST['length']) ) ) : 10;
		$page =   $start > 0 ? $start / $length : 1;
		$page = $page + 1;
		$offset = isset($_POST['start']) ? intval(sanitize_text_field( wp_unslash( $_POST['start']) ) ) : 0;
		$draw = isset( $_POST['draw'] )  ? intval(sanitize_text_field( wp_unslash( $_POST['draw']) ) ) : 1;
		



		global $wpdb, $table_prefix;
		$table_name_escaped = esc_sql( $table_prefix . 'cwvpb_critical_urls' );
		$search = isset( $_POST['search']['value'] ) ?  sanitize_text_field( wp_unslash( $_POST['search']['value'] ) ): ''; 
		if ($search) {
			$total_count = $wpdb->get_var( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_name_escaped} WHERE `url` LIKE %s ", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Already escaped.
					'%' . $wpdb->esc_like($search) . '%'
				)
			);

			$result = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table_name_escaped} WHERE `url` LIKE %s LIMIT %d, %d", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Already escaped.
					'%' . $wpdb->esc_like($search) . '%',
					$offset,
					$length
				)
				,
				ARRAY_A
			);
		} else {
			$total_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name_escaped}"); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Already escaped.
			$result = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table_name_escaped} LIMIT %d, %d", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Already escaped.
					$offset,
					$length
				)
				,
				ARRAY_A
			);
		}

		$formated_result = array();

		if (!empty($result)) {

			foreach ($result as $value) {
				$size = "NA";
				if ($value['status'] == 'cached') {
					$user_dirname = $this->cachepath();
					$size = round(filesize($user_dirname . '/' . md5($value['url']) . '.css') / 1024, 2) . ' KB';
					if (!$size) {
						$size = '<abbr title="File is not in cached directory. Please recheck in advance option">Deleted</abbr>';
					}
				}

				$formated_result[] = array(
					'<div><abbr title="' . $value['cached_name'] . '">' . $value['url'] . '</abbr>' . ($value['status'] == 'failed' ? '<a href="#" data-section="all" data-id="' . $value['id'] . '" class="cwvpb-resend-single-url dashicons dashicons-controls-repeat"></a>' : '') . ' </div>',
					'<span class="cwvpb-status-t">' . $value['status'] . '</span>',
					$size,
					$value['updated_at']
				);
			}

		}

		$retuernData = array(
			"draw" => $draw,
			"recordsTotal" => $total_count,
			"recordsFiltered" => $total_count,
			"data" => $formated_result
		);

		wp_send_json($retuernData);

	}
	public function cwvpsb_showdetails_data_completed()
	{

		if (!isset($_POST['cwvpsb_showdetails_data_completed_nonce'])) {
			return;
		}
		if (!wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cwvpsb_showdetails_data_completed_nonce'] ) ), 'cwvpsb_showdetails_data_completed_nonce')) {
			return;
		}

		$start = isset($_POST['start']) ? intval(sanitize_text_field( wp_unslash( $_POST['start']) ) ) : 1;
		$length = isset($_POST['length']) ? intval(sanitize_text_field( wp_unslash( $_POST['length']) ) ) : 10;
		$page =   $start > 0 ? $start / $length : 1;
		$page = $page + 1;
		$offset = isset($_POST['start']) ? intval(sanitize_text_field( wp_unslash( $_POST['start']) ) ) : 0;
		$draw = isset( $_POST['draw'] )  ? intval(sanitize_text_field( wp_unslash( $_POST['draw']) ) ) : 1;

		global $wpdb, $table_prefix;
		$table_name_escaped = esc_sql( $table_prefix . 'cwvpb_critical_urls');
		$search = isset( $_POST['search']['value'] ) ?  sanitize_text_field( wp_unslash( $_POST['search']['value'] ) ): ''; 
		if ($search) {
			$total_count = $wpdb->get_var( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_name_escaped} WHERE `url` LIKE %s AND `status`=%s", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Already escaped.
					'%' . $wpdb->esc_like($search) . '%',
					'cached'
				)
			);

			$result = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table_name_escaped} WHERE `url` LIKE %s AND `status`=%s LIMIT %d, %d", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Already escaped.
					'%' . $wpdb->esc_like($search) . '%',
					'cached',
					$offset,
					$length
				)
				,
				ARRAY_A
			);
		} else {
			$total_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name_escaped} Where `status`=%s", 'cached')); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Already escaped.
			$result = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table_name_escaped} Where `status`=%s LIMIT %d, %d", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Already escaped.
					'cached',
					$offset,
					$length
				)
				,
				ARRAY_A
			);
		}

		$formated_result = array();

		if (!empty($result)) {

			foreach ($result as $value) {

				if ($value['status'] == 'cached') {
					$user_dirname = $this->cachepath();
					$size = filesize($user_dirname . '/' . md5($value['url']) . '.css');
				}

				$formated_result[] = array(
					'<abbr title="' . $value['cached_name'] . '">' . $value['url'] . '</abbr>',
					'<span class="cwvpb-status-t">' . $value['status'] . '</span>',
					$size,
					$value['updated_at']
				);
			}

		}

		$retuernData = array(
			"draw" => $draw,
			"recordsTotal" => $total_count,
			"recordsFiltered" => $total_count,
			"data" => $formated_result
		);

		wp_send_json($retuernData);

	}
	public function cwvpsb_showdetails_data_failed()
	{

		if (!isset($_POST['cwvpsb_showdetails_data_failed_nonce'])) {
			return;
		}
		if (!wp_verify_nonce(sanitize_text_field( wp_unslash($_POST['cwvpsb_showdetails_data_failed_nonce'])), 'cwvpsb_showdetails_data_failed_nonce')) {
			return;
		}

		$start = isset($_POST['start']) ? intval(sanitize_text_field( wp_unslash( $_POST['start']) ) ) : 1;
		$length = isset($_POST['length']) ? intval(sanitize_text_field( wp_unslash( $_POST['length']) ) ) : 10;
		$page =   $start > 0 ? $start / $length : 1;
		$page = $page + 1;
		$offset = isset($_POST['start']) ? intval(sanitize_text_field( wp_unslash( $_POST['start']) ) ) : 0;
		$draw = isset( $_POST['draw'] )  ? intval(sanitize_text_field( wp_unslash( $_POST['draw']) ) ) : 1;

		global $wpdb, $table_prefix;
		$table_name_escaped = esc_sql($table_prefix . 'cwvpb_critical_urls');
		$search = isset( $_POST['search']['value'] ) ?  sanitize_text_field( wp_unslash( $_POST['search']['value'] ) ): ''; 
		if ( $search ) {
			$total_count = $wpdb->get_var( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( 
					"SELECT COUNT(*) FROM {$table_name_escaped} WHERE `url` LIKE %s AND `status`=%s", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Already escaped.
					'%' . $wpdb->esc_like($search) . '%',
					'failed'
				)
			);

			$result = $wpdb->get_results(  //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( 
					"SELECT * FROM {$table_name_escaped} WHERE `url` LIKE %s AND `status`=%s LIMIT %d, %d", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Already escaped.
					'%' . $wpdb->esc_like($search) . '%',
					'failed',
					$offset,
					$length
				)
				,
				ARRAY_A
			);
		} else {
			$total_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name_escaped} Where `status`=%s", 'failed')); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( 
					"SELECT * FROM {$table_name_escaped} Where `status`=%s LIMIT %d, %d", ////phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Already escaped.
					'failed',
					$offset,
					$length
				)
				,
				ARRAY_A
			);
		}

		$formated_result = array();

		if (!empty($result)) {

			foreach ($result as $value) {

				if ($value['status'] == 'cached') {
					$user_dirname = $this->cachepath();
					$size = filesize($user_dirname . '/' . md5($value['url']) . '.css');
				}

				$formated_result[] = array(
					'<div>' . $value['url'] . ' <a href="#" data-section="failed" data-id="' . $value['id'] . '" class="cwvpb-resend-single-url dashicons dashicons-controls-repeat"></a></div>',
					'<span class="cwvpb-status-t">' . $value['status'] . '</span>',
					$value['updated_at'],
					'<div><a data-id="id-' . $value['id'] . '" href="#" class="cwb-copy-urls-error button button-secondary">Copy Error</a><input id="id-' . $value['id'] . '" class="cwb-copy-urls-text" type="hidden" value="' . $value['failed_error'] . '"></div>'
				);
			}

		}

		$retuernData = array(
			"draw" => $draw,
			"recordsTotal" => $total_count,
			"recordsFiltered" => $total_count,
			"data" => $formated_result
		);

		wp_send_json($retuernData);

	}
	public function cwvpsb_showdetails_data_queue()
	{

		if (!isset($_POST['cwvpsb_showdetails_data_queue_nonce'])) {
			return;
		}
		if (!wp_verify_nonce(sanitize_text_field( wp_unslash($_POST['cwvpsb_showdetails_data_queue_nonce'])), 'cwvpsb_showdetails_data_queue_nonce')) {
			return;
		}

		$start = isset($_POST['start']) ? intval(sanitize_text_field( wp_unslash( $_POST['start']) ) ) : 1;
		$length = isset($_POST['length']) ? intval(sanitize_text_field( wp_unslash( $_POST['length']) ) ) : 10;
		$page =   $start > 0 ? $start / $length : 1;
		$page = $page + 1;
		$offset = isset($_POST['start']) ? intval(sanitize_text_field( wp_unslash( $_POST['start']) ) ) : 0;
		$draw = isset( $_POST['draw'] )  ? intval(sanitize_text_field( wp_unslash( $_POST['draw']) ) ) : 1;

		global $wpdb, $table_prefix;
		$table_name_escaped = esc_sql($table_prefix . 'cwvpb_critical_urls');
		$search = isset( $_POST['search']['value'] ) ?  sanitize_text_field( wp_unslash( $_POST['search']['value'] ) ): '';
		if ( $search ) {
			$total_count = $wpdb->get_var( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					"SELECT COUNT(*) FROM {$table_name_escaped} WHERE `url` LIKE %s AND `status`=%s", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Already escaped.
					'%' . $wpdb->esc_like($search) . '%',
					'queue'
				)
			);

			$result = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					"SELECT * FROM {$table_name_escaped} WHERE `url` LIKE %s AND `status`=%s LIMIT %d, %d", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'%' . $wpdb->esc_like($search) . '%',
					'queue',
					$offset,
					$length
				)
				,
				ARRAY_A
			);
		} else {
			$total_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name_escaped} Where `status`=%s", 'queue')); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( 
					"SELECT * FROM {$table_name_escaped} Where `status`=%s LIMIT %d, %d", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'queue',
					$offset,
					$length
				)
				,
				ARRAY_A
			);
		}

		$formated_result = array();

		if (!empty($result)) {

			foreach ($result as $value) {
				$size = "NA";
				if ($value['status'] == 'cached') {
					$user_dirname = $this->cachepath();
					$size = round(filesize($user_dirname . '/' . md5($value['url']) . '.css') / 1024, 2) . ' KB';
				}

				$formated_result[] = array(
					$value['url'],
					$value['status'],
					$size,
					$value['updated_at'],

				);
			}

		}

		$retuernData = array(
			"draw" => $draw,
			"recordsTotal" => $total_count,
			"recordsFiltered" => $total_count,
			"data" => $formated_result
		);

		wp_send_json($retuernData);

	}

	function cwvpsb_add_admin_notices()
	{
		if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
			$user = wp_get_current_user();
			if (in_array('administrator', (array) $user->roles)) {
				echo '<div class="notice notice-warning is-dismissible">
					  <p>' . esc_html__('Core Web Vitals &amp; PageSpeed Booster ', 'cwvpsb') . '<strong>' . esc_html__('"allow_url_fopen"', 'cwvpsb') . '</strong>' . esc_html__(' option to be enabled in PHP configuration to work.', 'cwvpsb') . ' </p>
					 </div>';
			}
		}
	}

}
$cwvpbCriticalCss = new CWVPSB_Critical_Css();