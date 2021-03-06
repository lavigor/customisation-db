<?php
/**
*
* This file is part of the phpBB Customisation Database package.
*
* @copyright (c) phpBB Limited <https://www.phpbb.com>
* @license GNU General Public License, version 2 (GPL-2.0)
*
* For full copyright and license information, please see
* the docs/CREDITS.txt file.
*
*/

namespace phpbb\titania\controller\ucp;

class subscriptions
{
	/** @var \phpbb\db\driver\driver_inteface */
	protected $db;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\request\request_interface */
	protected $request;

	/** @var \phpbb\titania\controller\helper */
	protected $helper;

	/** @var \phpbb\titania\config\config */
	protected $ext_config;

	/** @var string */
	protected $contribs_table;

	/** @var string */
	protected $topics_table;

	/** @var string */
	protected $watch_table;

	/**
	* Constructor.
	*
	* @param \phpbb\db\driver\driver_inteface $db
	* @param \phpbb\template\template $template
	* @param \phpbb\user $user
	* @param \phpbb\request\request_interface $request
	* @param \phpbb\titania\controller\helper $helper
	* @param \phpbb\titania\config\config $ext_config
	*/
	public function __construct(\phpbb\db\driver\driver_interface $db, \phpbb\template\template $template, \phpbb\user $user, \phpbb\request\request_interface $request, \phpbb\titania\controller\helper $helper, \phpbb\titania\config\config $ext_config)
	{
		$this->db = $db;
		$this->template = $template;
		$this->user = $user;
		$this->request = $request;
		$this->helper = $helper;
		$this->ext_config = $ext_config;
		$this->contribs_table = TITANIA_CONTRIBS_TABLE;
		$this->topics_table = TITANIA_TOPICS_TABLE;
		$this->watch_table = TITANIA_WATCH_TABLE;
	}

	/**
	* Delegates actions to appropriate methods.
	*
	* @param string $mode		Module mode
	* @param string $u_action	Module URL
	* @return null
	*/
	public function base($mode, $u_action)
	{
		if (!in_array($mode, array('items', 'sections')))
		{
			return;
		}
		$this->u_action = $u_action;

		// User wants to unsubscribe?
		if ($this->request->is_set_post('unsubscribe'))
		{
			$this->unsubscribe();
		}

		$this->{"display_$mode"}();

		add_form_key('ucp_front_subscription');
	}

	/**
	* Unsubscribe action.
	*
	* @throws \Exception	Throws exception if the form is not valid
	* @return null
	*/
	protected function unsubscribe()
	{
		if (!check_form_key('ucp_front_subscription'))
		{
			throw new \Exception($this->user->lang['FORM_INVALID']);
		}

		$subscriptions = $this->request->variable('subscriptions', array(0 => array(0 => 0)));

		foreach ($subscriptions as $type_id => $object_ids)
		{
			$object_ids = array_keys($object_ids);

			foreach ($object_ids as $object_id)
			{
				$this->delete_subscription($type_id, $object_id);
			}
		}
	}

	/**
	* Display subscription sections
	*
	* @return null
	*/
	protected function display_sections()
	{
		$object_types = array(
			TITANIA_SUPPORT,
			TITANIA_QUEUE,
			TITANIA_ATTENTION
		);
		$cases = array(
			TITANIA_SUPPORT		=> 'c.contrib_last_update',
		);

		$user_ids = $rows = array();
		$subscription_count = $this->get_subscription_count($object_types);
		$sort = $this->build_sort($subscription_count);

		$sql_ary = $this->get_subscription_sql_ary($cases, $object_types, TITANIA_SUPPORT);
		$sql = $this->db->sql_build_query('SELECT', $sql_ary);
		$result = $this->db->sql_query_limit($sql, $sort->limit, $sort->start);

		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
			$user_ids[] = (int) $row['contrib_user_id'];
		}
		$this->db->sql_freeresult($result);

		// Get user data
		\users_overlord::load_users($user_ids);

		foreach ($rows as $row)
		{
			switch ($row['watch_object_type'])
			{
				case TITANIA_SUPPORT:
					// Contribution no longer exists.
					if (!$row['contrib_id'])
					{
						$this->delete_subscription($row['watch_object_type'], $row['watch_object_id'], false);
						continue;
					}
					$vars = $this->get_support_tpl_row($row);
				break;

				case TITANIA_ATTENTION:
					$vars = $vars = $this->get_attention_tpl_row($row);
				break;

				case TITANIA_QUEUE:
					$vars = $this->get_queue_tpl_row($row);
				break;

				default:
					continue;
				break;
			}
			$this->template->assign_block_vars('subscriptions', $vars);
		}
	}

	/**
	* Display subscription items.
	*
	* @return null
	*/
	protected function display_items()
	{
		$object_types = array(TITANIA_CONTRIB, TITANIA_TOPIC);

		$subscription_count = $this->get_subscription_count($object_types);
		$sort = $this->build_sort($subscription_count);

		$cases = array(
			TITANIA_CONTRIB	=> 'c.contrib_last_update',
			TITANIA_TOPIC	=> 't.topic_last_post_time',
		);
		$sql_ary = $this->get_subscription_sql_ary($cases, $object_types, TITANIA_CONTRIB);

		$sql_ary['LEFT_JOIN'][] = array(
			'FROM'	=> array($this->topics_table => 't'),
			'ON'	=> 'w.watch_object_type = ' . TITANIA_TOPIC. '
							AND t.topic_id = w.watch_object_id',
		);

		// Additional tracking for support topics
		\titania_tracking::get_track_sql($sql_ary, TITANIA_TOPIC, 't.topic_id');
		\titania_tracking::get_track_sql($sql_ary, TITANIA_SUPPORT, 0, 'tsa');
		\titania_tracking::get_track_sql($sql_ary, TITANIA_SUPPORT, 't.parent_id', 'tsc');
		\titania_tracking::get_track_sql($sql_ary, TITANIA_QUEUE_DISCUSSION, 0, 'tqt');

		// Tracking for contributions
		\titania_tracking::get_track_sql($sql_ary, TITANIA_CONTRIB, 'c.contrib_id', 'tc');

		$sql = $this->db->sql_build_query('SELECT', $sql_ary);
		$result = $this->db->sql_query_limit($sql, $sort->limit, $sort->start);
		$user_ids = $contributions = $topics = array();

		while ($row = $this->db->sql_fetchrow($result))
		{
			\titania_tracking::store_from_db($row);
			$rows[] = $row;

			if ($row['watch_object_type'] == TITANIA_TOPIC)
			{
				$user_ids[] = (int) $row['topic_first_post_user_id'];
				$user_ids[] = (int) $row['topic_last_post_user_id'];
			}
			else
			{
				$user_ids[] = (int) $row['contrib_user_id'];
			}
		}
		$this->db->sql_freeresult($result);

		// Get user data
		\users_overlord::load_users($user_ids);

		foreach ($rows as $row)
		{
			if ($row['watch_object_type'] == TITANIA_TOPIC)
			{
				// Topic was deleted, remove all subscriptions for it.
				if (!$row['topic_id'])
				{
					$this->delete_subscription($row['watch_object_type'], $row['watch_object_id'], false);
					continue;
				}
				$vars = $this->get_topic_tpl_row($row);
			}
			else
			{
				// Contribution no longer exists.
				if (!$row['contrib_id'])
				{
					$this->delete_subscription($row['watch_object_type'], $row['watch_object_id'], false);
					continue;
				}
				$vars = $this->get_contribution_tpl_row($row);
			}
			$this->template->assign_block_vars('subscriptions', $vars);
		}

		$this->template->assign_vars(array(
			'S_WATCHED_ITEMS'	=> true,
		));
	}

	/**
	* Delete subscription.
	*
	* @param int $type					Object type
	* @param int $id					Object id
	* @param bool $current_user_only	Whether to limit deletion to current user
	*
	* @return null
	*/
	protected function delete_subscription($type, $id, $current_user_only = true)
	{
		$where = '';

		if ($current_user_only)
		{
			$where = 'watch_user_id = ' . (int) $this->user->data['user_id'] . ' AND ';
		}
		
		$sql = 'DELETE FROM ' . $this->watch_table . "
			WHERE $where watch_object_type = " . (int) $type . '
				AND watch_object_id = ' . (int) $id;
		$this->db->sql_query($sql);
	}

	/**
	* Get subscription count.
	*
	* @param array $object_types		Object types to limit count to
	* @return int
	*/
	protected function get_subscription_count($object_types)
	{
		$sql = 'SELECT COUNT(*) AS subscription_count
			FROM ' . $this->watch_table . '
			WHERE ' . $this->db->sql_in_set('watch_object_type', $object_types) . '
				AND watch_user_id = ' . (int) $this->user->data['user_id'];
		$this->db->sql_query($sql);
		$count = (int) $this->db->sql_fetchfield('subscription_count');
		$this->db->sql_freeresult();

		return $count;
	}

	/**
	* Get sql array to fetch subscriptions.
	*
	* @param array $cases
	* @param array $object_types
	* @param string $contrib_join_on
	*
	* @return array
	*/
	protected function get_subscription_sql_ary($cases, $object_types, $contrib_join_on)
	{
		$cases_sql = '';

		foreach ($cases as $type => $field)
		{
			$cases_sql = "WHEN $type THEN $field\n";
		}

		return array(
			'SELECT' => "*,
				CASE w.watch_object_type
					$cases_sql
				END AS time",

			'FROM' => array(
				$this->watch_table => 'w',
			),

			'LEFT_JOIN'	=> array(
				array(
					'FROM'	=> array($this->contribs_table => 'c'),
					'ON'	=> '(w.watch_object_type = ' . $contrib_join_on . ')
						AND c.contrib_id = w.watch_object_id',
				),
			),

			'WHERE' => 'w.watch_user_id = ' . $this->user->data['user_id'] . '
				AND ' . $this->db->sql_in_set('watch_object_type', $object_types),

			'ORDER_BY' => 'time DESC',
		);
	}

	/**
	* Build sort object.
	*
	* @param int $subscription_count
	* @return \titania_sort
	*/
	protected function build_sort($subscription_count)
	{
		// Setup the sort tool
		$sort = new \titania_sort();
		$sort->request();
		$sort->total = $subscription_count;
		$sort->build_pagination($this->u_action);

		return $sort;
	}

	/**
	* Get contribution template row.
	*
	* @param array $row
	* @return array
	*/
	protected function get_contribution_tpl_row($row)
	{
		\titania::_include('functions_display', 'titania_topic_folder_img');

		$contrib = $this->get_contrib($row);
		titania_topic_folder_img(
			$folder_img,
			$folder_alt,
			0,
			\titania_tracking::is_unread(TITANIA_CONTRIB, $contrib->contrib_id, $contrib->contrib_last_update)
		);

		return array(
			'FOLDER_STYLE'					=> $folder_img,
			'SUBSCRIPTION_AUTHOR_FULL'		=> \users_overlord::get_user($row['contrib_user_id'], '_full'),
			'SUBSCRIPTION_CONTRIB_TYPE'		=> $contrib->type->lang,
			'SUBSCRIPTION_DOWNLOADS'		=> $row['contrib_downloads'],
			'SUBSCRIPTION_ID'				=> $row['contrib_id'],
			'SUBSCRIPTION_TARGET'			=> $this->user->lang['SUBSCRIPTION_CONTRIB'],
			'SUBSCRIPTION_TIME'				=> $this->user->format_date($row['contrib_last_update']),
			'SUBSCRIPTION_TITLE'			=> $row['contrib_name'],
			'SUBSCRIPTION_TYPE'				=> $row['watch_object_type'],
			'SUBSCRIPTION_VIEWS'			=> $row['contrib_views'],

			'U_VIEW_SUBSCRIPTION'			=> $this->get_real_url($contrib->get_url()),

			'S_CONTRIB'						=> true,
		);
	}

	/**
	* Get topic template row.
	*
	* @param array $row
	* @return array
	*/
	protected function get_topic_tpl_row($row)
	{
		$topic = new \titania_topic;
		$topic->__set_array($row);
		$additional_unread_fields = array(
			array(
				'type'			=> TITANIA_SUPPORT,
				'id'			=> 0,
			),
			array(
				'type'			=> TITANIA_SUPPORT,
				'parent_match'	=> true,
			),
			array(
				'type' 			=> TITANIA_QUEUE_DISCUSSION,
				'id'			=> 0,
				'type_match'	=> true,
			),
		);
		$topic->additional_unread_fields = array_merge(
			$topic->additional_unread_fields,
			$additional_unread_fields
		);

		$subscription_target = '';
		$type_lang = array(
			TITANIA_QUEUE_DISCUSSION	=> 'SUBSCRIPTION_QUEUE_VALIDATION',
			TITANIA_QUEUE				=> 'SUBSCRIPTION_QUEUE',
			TITANIA_SUPPORT				=> 'SUBSCRIPTION_SUPPORT_TOPIC',
		);

		if (isset($type_lang[$row['topic_type']]))
		{
			$subscription_target = $this->user->lang($type_lang[$row['topic_type']]);
		}

		// Tracking check
		$last_read_mark = \titania_tracking::get_track(TITANIA_TOPIC, $topic->topic_id, true);
		$last_read_mark = max($last_read_mark, \titania_tracking::find_last_read_mark(
			$topic->additional_unread_fields,
			$topic->topic_type,
			$topic->parent_id
		));
		$topic->unread = $topic->topic_last_post_time > $last_read_mark;

		// Get the folder image
		$topic->topic_folder_img($folder_img, $folder_alt);

		return array(
			'FOLDER_STYLE'					=> $folder_img,
			'LAST_POST_IMG'					=> $this->user->img('icon_topic_latest', 'VIEW_LATEST_POST'),
			'SUBSCRIPTION_AUTHOR_FULL'		=> \users_overlord::get_user($row['topic_first_post_user_id'], '_full'),
			'SUBSCRIPTION_ID'				=> $row['topic_id'],
			'SUBSCRIPTION_LAST_AUTHOR_FULL' => \users_overlord::get_user($row['topic_last_post_user_id'], '_full'),
			'SUBSCRIPTION_LAST_TIME'		=> $this->user->format_date($row['topic_last_post_time']),
			'SUBSCRIPTION_TIME'				=> $this->user->format_date($row['topic_time']),
			'SUBSCRIPTION_TARGET'			=> $subscription_target,
			'SUBSCRIPTION_TITLE'			=> censor_text($row['topic_subject']),
			'SUBSCRIPTION_TYPE'				=> $row['watch_object_type'],

			'U_VIEW_SUBSCRIPTION'			=> $this->get_real_url($topic->get_url()),
			'U_VIEW_LAST_POST'				=> $this->get_real_url($topic->get_url(false, array(
				'p'		=> $topic->topic_last_post_id,
				'#'		=> 'p' . $topic->topic_last_post_id,
			))),

			'S_ACCESS_TEAMS'				=> $row['topic_access'] == TITANIA_ACCESS_TEAMS || $row['topic_type'] == TITANIA_QUEUE,
			'S_ACCESS_AUTHORS'				=> $row['topic_access'] == TITANIA_ACCESS_AUTHORS,
			'S_TOPIC'						=> true,
		);
	}

	/**
	* Get contrib support area template row.
	*
	* @param array $row
	* @return array
	*/
	protected function get_support_tpl_row($row)
	{
		$contrib = $this->get_contrib($row);

		return array(
			'SUBSCRIPTION_AUTHOR_FULL'		=> \users_overlord::get_user($row['contrib_user_id'], '_full'),
			'SUBSCRIPTION_ID'				=> $row['watch_object_id'],
			'SUBSCRIPTION_TARGET'			=> $this->user->lang['SUBSCRIPTION_SUPPORT'],
			'SUBSCRIPTION_TIME'				=> $this->user->format_date($row['contrib_last_update']),
			'SUBSCRIPTION_TITLE'			=> $row['contrib_name'],
			'SUBSCRIPTION_TYPE'				=> $row['watch_object_type'],

			'U_VIEW_SUBSCRIPTION'			=> $this->get_real_url($contrib->get_url('support'))
		);
	}

	/**
	* Get attention item template row.
	*
	* @param array $row
	* @return array
	*/
	protected function get_attention_tpl_row($row)
	{
		return array(
			'SUBSCRIPTION_ID'		=> $row['watch_object_id'],
			'SUBSCRIPTION_TIME'		=> $this->user->format_date($row['watch_mark_time']),
			'SUBSCRIPTION_TITLE'	=> $this->user->lang['SUBSCRIPTION_ATTENTION'],
			'SUBSCRIPTION_TYPE'		=> $row['watch_object_type'],

			'S_ATTENTION'			=> true,
			'S_ACCESS_TEAMS'		=> true,

			'U_VIEW_SUBSCRIPTION'	=> $this->get_real_url($this->helper->route('phpbb.titania.manage.attention')),
		);
	}

	/**
	* Get queue item template row.
	*
	* @param array $row
	* @return array
	*/
	protected function get_queue_tpl_row($row)
	{
		$queue_id = $row['watch_object_id'];
		$type = \titania_types::$types[$queue_id];

		return array(
			'SUBSCRIPTION_ID'		=> $queue_id,
			'SUBSCRIPTION_TARGET'	=> $type->lang,
			'SUBSCRIPTION_TIME'		=> $this->user->format_date($row['watch_mark_time']),
			'SUBSCRIPTION_TITLE'	=> $this->user->lang['SUBSCRIPTION_QUEUE'],
			'SUBSCRIPTION_TYPE'		=> $row['watch_object_type'],

			'S_QUEUE'				=> true,
			'S_ACCESS_TEAMS'		=> true,

			'U_VIEW_SUBSCRIPTION'	=> $this->get_real_url($this->helper->route('phpbb.titania.queue.type', array(
				'queue_type' => $type->url
			))),
		);
	}

	/**
	* Get contrib object.
	*
	* @param array $data	Contribution data
	* @return \titania_contribution
	*/
	protected function get_contrib($data)
	{
		$contrib = new \titania_contribution;
		$contrib->__set_array($data);
		$contrib->set_type($data['contrib_type']);

		return $contrib;
	}

	/**
	* Modify URL to point back to correct Titania location.
	*
	* Since the UCP module does not run from app.php, the generated route will
	* always point back under the phpBB board. The URL needs to be adjusted
	* if Titania is running from an app.php that is not under the board root.
	*
	* @param string $url
	* @return array
	*/
	protected function get_real_url($url)
	{
		if ($this->ext_config->titania_script_path)
		{
			return generate_board_url(true) .'/'. rtrim($this->ext_config->titania_script_path, '/') .
				substr($url, strlen(generate_board_url()));
		}
		return $url;
	}
}
