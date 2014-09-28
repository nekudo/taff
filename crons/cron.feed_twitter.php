<?php
ini_set('display_errors', 0);
error_reporting(0);

/**
 * Feed twitter accounts with news from xml feeds e.g.
 *
 * @author lemmingzshadow <web@lemmingzshadow.net>
 * @version 0.1
 */

class feed_twitter
{
	private $project_root = null;
	private $db_handling = null;
	private $twitter = null;
	private $config;

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
		include $this->project_root . 'libs/twitteroauth/twitteroauth.php';
	}

	/**
	 * Headquarters. Call different private functions from here.
	 * @param string $type What to do. Submitted as parameter from command line.
	 */
	public function feed($type)
	{
		switch($type)
		{
			case 'newsfeeds':
				$this->crawl_newsfeeds();
			break;

			default:
				echo 'Error: invalid type.';
			break;
		}
	}

	private function crawl_newsfeeds()
	{
		$this->db_handling->query("SELECT * FROM accounts");
		$accounts = $this->db_handling->get_result();

		foreach($accounts as $account)
		{
			// select feeds and keywords from databse:
			$query = "SELECT contentsources.id_contentsource, contentsources.url, contentsources.type, keywords.keyword, account_x_content.last_pubdate
				FROM contentsources
					INNER JOIN account_x_content ON contentsources.id_contentsource =  account_x_content.id_contentsource
					INNER JOIN keywords ON keywords.id_keyword = account_x_content.id_keyword
				WHERE account_x_content.id_account = %d
					AND contentsources.type = 'rss'";
			$query = $this->db_handling->prepare($query, array($account['id_account']));
			$this->db_handling->query($query);
			$temp = $this->db_handling->get_result();

			// tidy results:
			$feedinfo = array();
			foreach($temp as $value)
			{
				$feedinfo[$value['id_contentsource']]['url'] = $value['url'];
				$feedinfo[$value['id_contentsource']]['last_pubdate'] = $value['last_pubdate'];
				$feedinfo[$value['id_contentsource']]['keywords'][] = $value['keyword'];
			}
			unset($temp);

			$feeditems = $this->fetch_feeditems($feedinfo, $account['id_account']);
			$feeditems = $this->tidy_feeditems($feeditems, $feedinfo);
			$this->tweet_feeditems($feeditems, $account);
		}
	}

	/**
	 * Fetch content from feed-url. (title, url and pubdate of the single items.)
	 *
	 * @param array $feedinfo Information about the feeds (url, eg.)
	 * @return array Information from the feeds.
	 */
	private function fetch_feeditems($feedinfo, $id_account)
	{

		$index = 0;
		$feeditems = array();

		foreach(array_keys($feedinfo) as $id_contentsource)
		{
			$feedcontent = null;
			$curl = curl_init($feedinfo[$id_contentsource]['url']);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_TIMEOUT, 10);
			curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; de; rv:1.8.1.11) Gecko/20071127 Firefox/2.0.0.11');
			curl_setopt($curl, CURLOPT_REFERER, "http://www.google.com");
			$feedcontent = curl_exec($curl);
			curl_close($curl);

			$feedcontent = simplexml_load_string($feedcontent);
			if($feedcontent === false)
			{
				continue;
			}
			$items = $feedcontent->channel->item;
			$items_count = count($items);
			unset($feedcontent);
			// on first call of feed -> update pubDate but do not use items:
			if($feedinfo[$id_contentsource]['last_pubdate'] == 0)
			{
				$max_pubdate = 0;
				for($i = 0; $i < $items_count; $i++)
				{
					if(strtotime($items[$i]->pubDate) > $max_pubdate)
					{
						$max_pubdate = strtotime($items[$i]->pubDate);
					}
				}
				$query = "UPDATE account_x_content SET last_pubdate = %d WHERE id_account = %d AND id_contentsource = %d";
				$query = $this->db_handling->prepare($query, array($max_pubdate, $id_account, $id_contentsource));
				$this->db_handling->query($query);

				continue;
			}

			for($i = $items_count - 1; $i >= 0; $i--)
			{
				$feeditems[$index]['title'] = (string)$items[$i]->title;
				$feeditems[$index]['link'] = (string)$items[$i]->link;
				$feeditems[$index]['pubDate'] = strtotime($items[$i]->pubDate);
				$feeditems[$index]['contentsource'] = $id_contentsource;
				$index++;
			}
		}
		return $feeditems;
	}

	private function tidy_feeditems($feeditems, $feedinfo)
	{
		$feeditems_count = count($feeditems);


		// kick items not containig a given keyword in title:
		for($i = 0; $i < $feeditems_count; $i++)
		{
			$keyword_found = false;
			$needle = implode('|', $feedinfo[$feeditems[$i]['contentsource']]['keywords']);
			$keyword_found = preg_match('/('.$needle.')/is', $feeditems[$i]['title']);
			if($keyword_found == 0 || $keyword_found === false)
			{
				unset($feeditems[$i]);
			}
		}
		$feeditems = array_merge($feeditems, array());
		$feeditems_count = count($feeditems);


		// kick items which where already posted: (check pubDate)
		for($i = 0; $i < $feeditems_count; $i++)
		{
			if($feeditems[$i]['pubDate'] <= $feedinfo[$feeditems[$i]['contentsource']]['last_pubdate'])
			{
				unset($feeditems[$i]);
			}
		}
		$feeditems = array_merge($feeditems, array());

		return $feeditems;
	}

	private function tweet_feeditems($feeditems, $account)
	{
		$this->twitter = new TwitterOAuth($account['oauth_consumer_key'], $account['oauth_consumer_secret'], $account['oauth_access_key'], $account['oauth_access_secret']);
		$feeditems_count = count($feeditems);
		for($i = 0; $i < $feeditems_count; $i++)
		{
			$short_url = $this->get_shorturl($feeditems[$i]['link']);
			$message = $feeditems[$i]['title'] . ': ' . $short_url;
			$query = "UPDATE account_x_content SET last_pubdate = %d WHERE id_account = %d AND id_contentsource = %d";
			$query = $this->db_handling->prepare($query, array($feeditems[$i]['pubDate'], $account['id_account'], $feeditems[$i]['contentsource']));
			$this->db_handling->query($query);

			//$this->twitter->updateStatus($message);
			$this->twitter->post('statuses/update', array('status' => $message));
		}
	}

	private function get_shorturl($url)
	{
		$encode_url = urlencode($url);
		$temp = null;
		$curl = curl_init('http://api.bit.ly/shorten?version=2.0.1&longUrl='.$encode_url.'&login=YOUR_USERNAME&apiKey=YOUR_PASSWORD&history=1');
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_TIMEOUT, 10);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; de; rv:1.8.1.11) Gecko/20071127 Firefox/2.0.0.11');
		curl_setopt($curl, CURLOPT_REFERER, "http://domain.de");
		$temp = curl_exec($curl);
		curl_close($curl);
		$temp = json_decode($temp, true);
		foreach(array_keys($temp['results']) as $key)
		{
			$bitly_url = $temp['results'][$key]['shortUrl'];
			return $bitly_url;
		}
		unset($temp);

		return false;
	}
}

/**
 * Cronjob call:
 */
$feed_twitter = new feed_twitter();
$type = (array_key_exists('1', $argv)) ? trim($argv[2]) : 'newsfeeds';
$feed_twitter->feed($type);