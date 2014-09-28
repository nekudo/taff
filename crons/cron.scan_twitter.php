<?php
ini_set('display_errors', 0);
error_reporting(0);

/**
 * Cronjob to scan twitter public timeline for users using certain keywords.
 *
 * @author lemmingzshadow
 * @version 0.1
 */

class twitter_scanner
{
	private $project_root = null;
	private $config = null;
	private $db_handling = null;
	private $twitter = null;
	private $twitter_oauth = null;

	public function __construct()
	{
		$this->init();
	}

	/**
	 * Load settings, objects, eg.
	 */
	private function init()
	{
		// set root path
		$this->project_root = str_replace('crons', '', dirname(__FILE__));

		// include config:
		include $this->project_root . 'config.inc.php';
		$this->config = $config;

		// init db_handling:
		include $this->project_root . 'libs/class.db_handling.php';
		$this->db_handling = db_handling::get_instance($this->config['db_config']);

		// init twitter api-handler:
		include $this->project_root . 'libs/Arc90/Service/Twitter.php';
		$this->twitter = new Arc90_Service_Twitter();
		//$this->twitter->setSSL(false);

		include $this->project_root . 'libs/twitteroauth/twitteroauth.php';
	}

	public function scan($type = 'public')
	{
		switch($type)
		{
			case 'public':
				/** @todo: crawl public timeline **/
			break;

			case 'search':
				$this->crawl_searchresults();
			break;

			case 'tidy_friendships':
				$this->tidy_friendships();
			break;

			default:
				echo 'Error: unknown type.';
			break;
		}
	}

	/**
	 * Get twitter searchresults for certain keywords and follow users
	 * if language fits to account settings.
	 */
	private function crawl_searchresults()
	{
		$twitter_search = $this->twitter->searchApi();
		$keywords = $this->get_keywords();
		foreach($keywords as $value)
		{
			$temp = $twitter_search->search($value['keyword']);
			$temp = json_decode($temp->data);
			$searchresult = $temp->results;
			$friend_requests = $this->analyze_searchresults($searchresult, $value['id_keyword']);			
			if(count($friend_requests) > 0)
			{
				$this->follow_accounts($friend_requests);
			}
		}
	}

	private function tidy_friendships()
	{
		// get accounts:
		$query = "SELECT * FROM accounts";
		$this->db_handling->query($query);
		$accounts = $this->db_handling->get_result();

		for($i = 0, $accounts_count = count($accounts); $i < $accounts_count; $i++)
		{
			// get list of users we follwed 3days ago (or more)
			$query = "SELECT account_follows.id_nick, twitter_nicks.nickname
				FROM account_follows
					INNER JOIN twitter_nicks ON twitter_nicks.id_nick = account_follows.id_nick
				WHERE account_follows.id_account = %d
					AND DATE_ADD(account_follows.timestamp, INTERVAL 1 DAY) < NOW()
					AND DATE_FORMAT(account_follows.timestamp, '\%Y-\%m-\%d') = DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 DAY), '\%Y-\%m-\%d')
					AND account_follows.unfollowed != 1";
			$query = $this->db_handling->prepare($query, array($accounts[$i]['id_account']));			
			$this->db_handling->query($query);
			
			if($this->db_handling->get_result_count() > 0)
			{
				$following = $this->db_handling->get_result();			
				$following_count = count($following);

				// get list of users currently following this accout:
				//$this->twitter->setAuth($accounts[$i]['username'], $accounts[$i]['password']);
				//$temp = $this->twitter->getFollowers();
				//$temp = json_decode($temp->data);

				$this->twitter_oauth = new TwitterOAuth($accounts[$i]['oauth_consumer_key'], $accounts[$i]['oauth_consumer_secret'], $accounts[$i]['oauth_access_key'], $accounts[$i]['oauth_access_secret']);
				$temp = $this->twitter_oauth->get('statuses/followers/'.$accounts[$i]['username']);
				
				$followers_count = count($temp);
				if($followers_count == 0)
				{
					return false;
				}
				
				$followers = array();
				for($j = 0; $j < $followers_count; $j++)
				{
					$followers[$temp[$j]->screen_name] = true;
				}
				unset($temp);

				// unfollow users not following this account:
				for($j = 0; $j < $following_count; $j++)
				{
					if(!array_key_exists($following[$j]['nickname'], $followers))
					{
						$query = "UPDATE account_follows SET unfollowed = 1 WHERE id_account = %d AND id_nick = %d";
						$query = $this->db_handling->prepare($query, array($accounts[$i]['id_account'], $following[$j]['id_nick']));
						$this->db_handling->query($query);
						
						//$this->twitter->destroyFriendship($following[$j]['nickname']);						
						$this->twitter_oauth->post('friendships/destroy/'.$following[$j]['nickname']);						
					}
				}
			}
			else
			{
				return true;
			}
		}
	}

	/**
	 * Check which accounts are interested in users whith this keyword. Also
	 * check if language matches.
	 *
	 * @param object $searchresults Searchresults returned from twitter.
	 * @param int $id_keyword Keyword-ID for this searchresult.
	 * @return array Information which users machtes to which account.
	 */
	private function analyze_searchresults($searchresults, $id_keyword)
	{
		$friend_requsts = array();
		$accounts = $this->get_accounts_by_keyword($id_keyword);		
		for($i = 0, $searchresult_count = count($searchresults); $i < $searchresult_count; $i++)
		{
			$tweet_lang = $searchresults[$i]->iso_language_code;
			for($j = 0, $accounts_count = count($accounts); $j < $accounts_count; $j++)
			{
				$account_lang = $accounts[$j]['language_code'];
				if($account_lang == 'all' || $account_lang == $tweet_lang)
				{
					$friend_requsts[$accounts[$j]['id_account']][] = $searchresults[$i]->from_user;
				}
			}
		}
		return $friend_requsts;
	}

	private function follow_accounts($friend_requests)
	{
		foreach($friend_requests as $id_account => $follownicks)
		{
			$accountdata = $this->get_accountdata($id_account);
			//$this->twitter->setAuth($accountdata['username'], $accountdata['password']);
			$this->twitter_oauth = new TwitterOAuth($accountdata['oauth_consumer_key'], $accountdata['oauth_consumer_secret'], $accountdata['oauth_access_key'], $accountdata['oauth_access_secret']);
			for($i = 0, $follownicks_count = count($follownicks); $i < $follownicks_count; $i++)
			{
				// get nickid:
				$twitter_nickid = $this->get_nickid($follownicks[$i]);

				// check if already following:
				if($this->is_following($id_account, $twitter_nickid) === false)
				{
					$query = $this->db_handling->prepare("INSERT INTO account_follows (id_account, id_nick) VALUES (%d, %d)", array($id_account, $twitter_nickid));
					$this->db_handling->query($query);

					//$temp = $this->twitter->createFriendship($follownicks[$i]);					
					$this->twitter_oauth->post('friendships/create/'.$follownicks[$i]);					
				}
			}
		}
	}

	/**
	 * Returns account data for given id.
	 *
	 * @param int $id_account Account-ID
	 * @return array Account-Information.
	 */
	private function get_accountdata($id_account)
	{
		// get basic accountdata:
		$accountdata = array();
		$query = $this->db_handling->prepare("SELECT * FROM accounts WHERE id_account = %d", array($id_account));
		$this->db_handling->query($query);
		$accountdata = $this->db_handling->get_result(true);

		return $accountdata;
	}

	/**
	 * Check if account follows a twitter user.
	 *
	 * @param int $id_account Account-ID.
	 * @param int $id_nick Twitternick-ID.
	 * @return bool True if following, false if not.
	 */
	private function is_following($id_account, $id_nick)
	{
		$query = $this->db_handling->prepare("SELECT id_nick FROM account_follows WHERE id_account = %d AND id_nick = %d", array($id_account, $id_nick));
		$this->db_handling->query($query);
		if($this->db_handling->get_result_count() == 1)
		{
			return true;
		}
		return false;
	}

	/**
	 * Fetch keywordlist from database.
	 *
	 * @return array List og keywords and according ids.
	 */
	private function get_keywords()
	{
		$query = "SELECT * FROM keywords";
		$this->db_handling->query($query);
		return $this->db_handling->get_result();
	}

	/**
	 * Returns accounts related to a given keyword.
	 * @param int $id_keyword Keyword-ID
	 * @return array List of accounts.
	 */
	private function get_accounts_by_keyword($id_keyword)
	{
		$query = "SELECT accounts.id_account, accounts.username, accounts.language_code
			FROM accounts
				INNER JOIN account_x_keyword ON account_x_keyword.id_account = accounts.id_account
			WHERE account_x_keyword.id_keyword = %d";
		$query = $this->db_handling->prepare($query, array($id_keyword));
		$this->db_handling->query($query);
		return $this->db_handling->get_result();
	}

	/**
	 * Get if for a twitter nick from database. If not existing, insert and
	 * return resulting id.
	 *
	 * @param string $nickname The twitter nickname.
	 * @return int Id from database.
	 */
	private function get_nickid($nickname)
	{
		$query = $this->db_handling->prepare("SELECT id_nick FROM twitter_nicks WHERE nickname = %s", array($nickname));
		$this->db_handling->query($query);
		if($this->db_handling->get_result_count() == 1)
		{
			$temp = $this->db_handling->get_result(true);
			return $temp['id_nick'];
		}
		else
		{
			$query = $this->db_handling->prepare("INSERT INTO twitter_nicks (nickname) VALUES (%s)", array($nickname));
			$this->db_handling->query($query);
			return $this->db_handling->get_insert_id();
		}
	}
}

/**
 * Cronjob call:
 */
$twitter_scanner = new twitter_scanner();
$type = (array_key_exists('1', $argv)) ? trim($argv[2]) : 'public';
$twitter_scanner->scan($type);