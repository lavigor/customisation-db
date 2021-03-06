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

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/*
 * Generate the category select (much is from the make_jumpbox function)
 *
 * @param array $selected
 * @param bool $is_manage			Whether in category management, in which case all are listed
 * @param bool $disable_parents		Whether to disable categories that do not have a contribution type
 * @param bool|int $category_type	Category type to limit list to
 * @return void
 */
function generate_category_select($selected = false, $is_manage = false, $disable_parents = true, $category_type = false)
{
	if (!is_array($selected))
	{
		$selected = array($selected);
	}

	$right = $padding = 0;
	$padding_store = array('0' => 0);

	$categories = titania::$cache->get_categories();
	$hidden_categories = array();
	$category = new \titania_category;

	foreach ($categories as $row)
	{
		$type = (isset(titania_types::$types[$row['category_type']])) ? titania_types::$types[$row['category_type']] : false;

		if (!$type || !$type->acl_get('submit') || ($category_type && $type->id != $category_type))
		{
			continue;
		}
		$category->__set_array($row);

		if ($row['left_id'] < $right)
		{
			$padding++;
			$padding_store[$row['parent_id']] = $padding;
		}
		else if ($row['left_id'] > $right + 1)
		{
			$padding = (isset($padding_store[$row['parent_id']])) ? $padding_store[$row['parent_id']] : $padding;
		}

		$right = $row['right_id'];

		if (!$is_manage)
		{
			// Non-postable category with no children, don't display
			$not_postable = $row['category_type'] == 0 && ($row['left_id'] + 1 == $row['right_id']);
			$hidden = !$row['category_visible'] || in_array($row['parent_id'], $hidden_categories);
			$team_only_restriction = $category->is_option_set('team_only') && !$type->acl_get('moderate');

			if ($not_postable || $hidden || $team_only_restriction)
			{
				if ($hidden)
				{
					$hidden_categories[] = $row['category_id'];
				}
				continue;
			}
		}

		phpbb::$template->assign_block_vars('category_select', array(
			'S_SELECTED'		=> in_array($row['category_id'], $selected),
			'S_DISABLED'		=> $row['category_type'] == 0 && $disable_parents,

			'VALUE'				=> $row['category_id'],
			'TYPE'				=> $row['category_type'],
			'NAME'				=> $category->get_name(),
		));

		for ($i = 0; $i < $padding; $i++)
		{
			phpbb::$template->assign_block_vars('category_select.level', array());
		}
	}
}

/*
 * Create a select with the contrib types
 *
 * @param array $selected
 * @return void
 */
function generate_type_select($selected = false)
{
	phpbb::$template->assign_block_vars('type_select', array(
		'S_IS_SELECTED'		=> ($selected === false) ? true : false,

		'VALUE'				=> 0,
		'NAME'				=> (isset(phpbb::$user->lang['SELECT_CONTRIB_TYPE'])) ? phpbb::$user->lang['SELECT_CONTRIB_TYPE'] : '--',
	));

	foreach (titania_types::$types as $key => $type)
	{
		if (!$type->acl_get('submit'))
		{
			continue;
		}

		phpbb::$template->assign_block_vars('type_select', array(
			'S_IS_SELECTED'		=> ($key == $selected) ? true : false,

			'VALUE'				=> $key,
			'NAME'				=> (isset(phpbb::$user->lang['SELECT_CONTRIB_TYPE'])) ? $type->lang : $type->langs,
		));
	}
}

/*
 * Create a select with the phpBB versions
 *
 * @param array $selected
 * @param bool|array $branches		Branches to output. If false, uses value
 *	generated from get_allowed_phpbb_branches().
 * @return void
 */
function generate_phpbb_version_select($selected = false, $branches = false)
{
	if (!$branches)
	{
		$branches = get_allowed_phpbb_branches();
	}

	foreach ($branches as $branch => $row)
	{
		phpbb::$template->assign_block_vars('phpbb_branches', array(
			'S_IS_SELECTED'		=> (is_array($selected) && in_array($branch, $selected)) ? true : false,

			'VALUE'				=> $branch,
			'NAME'				=> $row['name'],
		));
	}
}

/**
* Get the branches we are allowed to upload to
*/
function get_allowed_phpbb_branches()
{
	$versions = array();

	foreach (titania::$config->phpbb_versions as $branch => $row)
	{
		if (!$row['allow_uploads'])
		{
			continue;
		}

		$versions[$branch] = $row;
	}

	return $versions;
}

/**
* Generate the _options flag from the given settings
*
* @param bool $bbcode
* @param bool $smilies
* @param bool $url
* @return int options flag
*/
function get_posting_options($bbcode, $smilies, $url)
{
	return (($bbcode) ? OPTION_FLAG_BBCODE : 0) + (($smilies) ? OPTION_FLAG_SMILIES : 0) + (($url) ? OPTION_FLAG_LINKS : 0);
}

/**
* Reverses the posting options
*
* @param int $options The given posting options
* @param bool $bbcode
* @param bool $smilies
* @param bool $url
*/
function reverse_posting_options($options, &$bbcode, &$smilies, &$url)
{
	$bbcode = ($options & OPTION_FLAG_BBCODE) ? true : false;
	$smilies = ($options & OPTION_FLAG_SMILIES) ? true : false;
	$url = ($options & OPTION_FLAG_LINKS) ? true : false;
}

/*
 * Create select with Titania's accesses
 *
 * @param integer $default
 * @param integer $min_access Minimum access level to display
 * @return string
 */
function titania_access_select($default = false, $min_access = TITANIA_ACCESS_PUBLIC)
{
	if (titania::$access_level == TITANIA_ACCESS_PUBLIC)
	{
		return '';
	}

	$access_types = array(
		TITANIA_ACCESS_TEAMS 	=> 'ACCESS_TEAMS',
		TITANIA_ACCESS_AUTHORS 	=> 'ACCESS_AUTHORS',
		TITANIA_ACCESS_PUBLIC 	=> 'ACCESS_PUBLIC',
	);

	if ($default === false)
	{
		$default = TITANIA_ACCESS_PUBLIC;
	}

	$s_options = '';

	foreach ($access_types as $type => $lang_key)
	{
		if (titania::$access_level > $type || $min_access < $type)
		{
			continue;
		}

		$selected = ($default == $type) ? ' selected="selected"' : '';

		$s_options .= '<option value="' . $type . '"' . $selected . '>' . phpbb::$user->lang[$lang_key] . '</option>';
	}

	return $s_options;
}

/**
* Get the author user_ids from the list of usernames
*
* @param string $list the list of usernames (after executed it will be an array of the user_ids)
* @param array $missing array of usernames that could not be found (will be populated if any)
* @param string $separator the delimiter
*/
function get_author_ids_from_list(&$list, &$missing, $separator = "\n")
{
	if (!$list)
	{
		$list = $missing = array();
		return true;
	}

	$usernames = explode($separator, $list);
	$list = array();

	foreach ($usernames as &$username)
	{
		$missing[$username] = $username;
		$username = utf8_clean_string($username);
	}

	$sql = 'SELECT username, username_clean, user_id FROM ' . USERS_TABLE . '
		WHERE ' . phpbb::$db->sql_in_set('username_clean', $usernames) . '
		AND user_type != ' . USER_IGNORE;
	$result = phpbb::$db->sql_query($sql);
	while ($row = phpbb::$db->sql_fetchrow($result))
	{
		unset($missing[$row['username']], $missing[$row['username_clean']]);

		$list[$row['username']] = $row['user_id'];
	}

	if (sizeof($missing))
	{
		return false;
	}

	return true;
}

/**
 * Allow to create a new topic, to reply to a topic, to edit a post or the first_post of a topic in database
 * @param $mode post/reply/edit/edit_first_post/edit_last_post
 * @param $options array Array with post data, see our documentation for exact required items
 * @param $poll array Array with poll options.
 *
 * @return mixed false if there was an error, else topic_id when $mode is post, post_id when $mode is reply, true when mode is edit
 */
function phpbb_posting($mode, &$options, $poll = array())
{
	if (!in_array($mode, array('post', 'reply', 'edit', 'edit_first_post', 'edit_last_post')))
	{
		return false;
	}

	phpbb::_include('bbcode', false, 'bbcode');
	phpbb::_include('message_parser', false, 'parse_message');
	phpbb::_include('functions_posting', 'submit_post', false);

	// Set some defaults
	$options = array_merge($options, array(
		'enable_bbcode'			=> true,
		'enable_urls'			=> true,
		'enable_smilies'		=> true,
		'topic_type'			=> POST_NORMAL,
	));

	$message_parser = new parse_message($options['post_text']);

	// Get the data we need
	if ($mode == 'reply')
	{
		$sql = 'SELECT f.*, t.*
			FROM ' . FORUMS_TABLE . ' f, ' . TOPICS_TABLE . ' t
			WHERE t.topic_id = ' . (int) $options['topic_id'] . '
				AND f.forum_id = t.forum_id';
		$result = phpbb::$db->sql_query($sql);
		$post_data = phpbb::$db->sql_fetchrow($result);
		phpbb::$db->sql_freeresult($result);
	}
	else if ($mode == 'edit')
	{
		$sql = 'SELECT f.*, t.*, p.*
			FROM ' . FORUMS_TABLE . ' f, ' . TOPICS_TABLE . ' t, ' . POSTS_TABLE . ' p
			WHERE p.post_id = ' . (int) $options['post_id'] . '
				AND t.topic_id = p.topic_id
				AND f.forum_id = t.forum_id';
		$result = phpbb::$db->sql_query($sql);
		$post_data = phpbb::$db->sql_fetchrow($result);
		phpbb::$db->sql_freeresult($result);
	}
	else if ($mode == 'edit_first_post')
	{
		$sql = 'SELECT f.*, t.*, p.*
			FROM ' . FORUMS_TABLE . ' f, ' . TOPICS_TABLE . ' t, ' . POSTS_TABLE . ' p
			WHERE t.topic_id = ' . (int) $options['topic_id'] . '
				AND p.post_id = t.topic_first_post_id
				AND f.forum_id = t.forum_id';
		$result = phpbb::$db->sql_query($sql);
		$post_data = phpbb::$db->sql_fetchrow($result);
		phpbb::$db->sql_freeresult($result);

		//http://tracker.phpbb.com/browse/PHPBB3-9644
		$mode = 'edit';
	}
	else if ($mode == 'edit_last_post')
	{
		$sql = 'SELECT f.*, t.*, p.*
			FROM ' . FORUMS_TABLE . ' f, ' . TOPICS_TABLE . ' t, ' . POSTS_TABLE . ' p
			WHERE t.topic_id = ' . (int) $options['topic_id'] . '
				AND p.post_id = t.topic_last_post_id
				AND f.forum_id = t.forum_id';
		$result = phpbb::$db->sql_query($sql);
		$post_data = phpbb::$db->sql_fetchrow($result);
		phpbb::$db->sql_freeresult($result);

		//http://tracker.phpbb.com/browse/PHPBB3-9644
		$mode = 'edit';
	}
	else // post
	{
		$sql = 'SELECT *
			FROM ' . FORUMS_TABLE . '
			WHERE forum_id = ' . (int) $options['forum_id'];
		$result = phpbb::$db->sql_query($sql);
		$post_data = phpbb::$db->sql_fetchrow($result);
		phpbb::$db->sql_freeresult($result);
	}

	if (!$post_data)
	{
		return false;
	}

	// If we need to post the message as a different user other than the one logged in
	if (isset($options['poster_id']) && $options['poster_id'])
	{
		// Some data for the ugly fix below :P
		$sql = 'SELECT username, user_colour, user_permissions, user_type
			FROM ' . USERS_TABLE . '
			WHERE user_id = ' . (int) $options['poster_id'];
		$result = phpbb::$db->sql_query($sql);
		$user_data = phpbb::$db->sql_fetchrow($result);
		phpbb::$db->sql_freeresult($result);

		if (!$user_data)
		{
			return false;
		}

		// Ugly fix, to be sure it is posted for the right user ;)
		$old_user_data = phpbb::$user->data;
		phpbb::$user->data['user_id'] = $options['poster_id'];
		phpbb::$user->data['username'] = $user_data['username'];
		phpbb::$user->data['user_colour'] = $user_data['user_colour'];
		phpbb::$user->data['user_permissions'] = $user_data['user_permissions'];
		phpbb::$user->data['user_type'] = $user_data['user_type'];

		// Same for auth, be sure its posted with correct permissions :)
		$old_auth = phpbb::$auth;

		phpbb::$auth = new \phpbb\auth\auth();
		phpbb::$auth->acl(phpbb::$user->data);
	}

	// Parse the BBCode
	if ($options['enable_bbcode'])
	{
		$message_parser->parse($options['enable_bbcode'], (phpbb::$config['allow_post_links']) ? $options['enable_urls'] : false, $options['enable_smilies'], true, (bool) phpbb::$config['allow_post_flash'],  true, phpbb::$config['allow_post_links']);
	}

	// Setup the settings we need to send to submit_post
	$data = array(
		'topic_title'			=> $options['topic_title'],

		'enable_bbcode'			=> (bool) $options['enable_bbcode'],
		'enable_smilies'		=> (bool) $options['enable_smilies'],
		'enable_urls'			=> (bool) $options['enable_urls'],
		'message_md5'			=> (string) md5($message_parser->message),
		'bbcode_bitfield'		=> $message_parser->bbcode_bitfield,
		'bbcode_uid'			=> $message_parser->bbcode_uid,
		'message'				=> $message_parser->message,

		'force_approved_state'	=> true,

		// http://tracker.phpbb.com/browse/PHPBB3-9635
		'post_time'				=> time(),

		// False for both will not add nor remove notifications
		'notify_set'			=> false,
		'notify'				=> false,
	);

	switch ($mode)
	{
		case 'post':
			$data = array_merge(array(
				'icon_id'				=> (isset($options['icon_id'])) ? $options['icon_id'] : 0,
				'poster_id'				=> (isset($options['poster_id']) && $options['poster_id']) ? (int) $options['poster_id'] : phpbb::$user->data['user_id'],
				'enable_sig'			=> (isset($options['enable_sig'])) ? (bool) $options['enable_sig'] : true,
				'post_edit_locked'		=> (isset($options['post_edit_locked'])) ? $options['post_edit_locked'] : false,
			), $data);
		break;

		case 'reply':
			$data = array_merge(array(
				'poster_id'				=> (isset($options['poster_id']) && $options['poster_id']) ? (int) $options['poster_id'] : phpbb::$user->data['user_id'],
				'enable_sig'			=> (isset($options['enable_sig'])) ? (bool) $options['enable_sig'] : true,
				'post_edit_locked'		=> (isset($options['post_edit_locked'])) ? $options['post_edit_locked'] : false,
			), $data);
		break;
	}

	// Merge the data we grabbed from the forums/topics/posts tables
	$data = array_merge($post_data, $data);

	// In case bbcode_bitfield is not set when it should
	$data['bbcode_bitfield'] = ($data['bbcode_bitfield'] != '') ? $data['bbcode_bitfield'] : $message_parser->bbcode_bitfield;

	// Aaaand, submit it.
	switch ($mode)
	{
		case 'post' :
		case 'reply' :
			submit_post($mode, $options['topic_title'], ((isset($options['poster_id']) && $options['poster_id']) ? $user_data['username'] : phpbb::$user->data['username']), $options['topic_type'], $poll, $data);
		break;

		default :
			submit_post($mode, $options['topic_title'], phpbb::$user->data['username'], $options['topic_type'], $poll, $data);
		break;
	}

	// Change the status?  submit_post does not support setting this
	if (isset($options['topic_status']))
	{
		$sql = 'UPDATE ' . TOPICS_TABLE . '
			SET topic_status = ' . (int) $options['topic_status'] . '
			WHERE topic_id = ' . $data['topic_id'] . '
				AND topic_moved_id = 0';
		phpbb::$db->sql_query($sql);
	}

	// Restore the user data
	if (isset($options['poster_id']) && $options['poster_id'])
	{
		phpbb::$user->data = $old_user_data;
		$auth = $old_auth;
	}

	// Add the new data to the options (to grab post/topic id/etc if we want it later)
	$options = array_merge($data, $options);

	if ($mode == 'post')
	{
		return $data['topic_id'];
	}
	else if ($mode == 'reply')
	{
		return $data['post_id'];
	}

	return true;
}
