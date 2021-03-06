<?php
/**
 * Tweet Display Back Module for Joomla!
 *
 * @copyright  Copyright (C) 2010-2016 Michael Babker. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

defined('_JEXEC') or die;

use Joomla\Registry\Registry;

/**
 * Helper class for Tweet Display Back
 *
 * @since  1.0
 */
class ModTweetDisplayBackHelper
{
	/**
	 * OAuth bearer token for use in API requests
	 *
	 * @var    BDBearer
	 * @since  3.1
	 */
	protected $bearer;

	/**
	 * Cache adapter
	 *
	 * @var    JCacheController
	 * @since  4.0
	 */
	protected $cache;

	/**
	 * Container for cache IDs
	 *
	 * @var    array
	 * @since  4.0
	 */
	protected $cacheIds = ['tweet' => '', 'user' => ''];

	/**
	 * JHttp connector
	 *
	 * @var    JHttp
	 * @since  3.1
	 */
	protected $connector;

	/**
	 * Flag to determine whether caching is supported
	 *
	 * @var    boolean
	 * @since  3.0
	 */
	public $hasCaching = false;

	/**
	 * Flag to determine whether data has been fully processed
	 *
	 * @var    boolean
	 * @since  3.0
	 */
	public $isProcessed = false;

	/**
	 * ID of the currently active module
	 *
	 * @var    integer
	 * @since  3.0
	 */
	public $moduleId;

	/**
	 * Module parameters
	 *
	 * @var    Registry
	 * @since  3.0
	 */
	protected $params;

	/**
	 * Container for the tweet response object
	 *
	 * @var    object
	 * @since  3.0
	 */
	public static $tweets;

	/**
	 * Container for the formatted module data
	 *
	 * @var    array
	 * @since  3.0
	 */
	public $twitter = [];

	/**
	 * Container for the user profile response object
	 *
	 * @var    array
	 * @since  3.0
	 */
	public static $user;

	/**
	 * Constructor
	 *
	 * @param   Registry  $params  The module parameters
	 *
	 * @since   3.0
	 */
	public function __construct($params)
	{
		// Store the module params
		$this->params = $params;

		// Set up our Registry object for the JHttp connector
		$options = new Registry;

		// Set the user agent
		$options->set('userAgent', 'TweetDisplayBack/4.0');

		// Use a 30 second timeout
		$options->set('timeout', 30);

		// Include the BabDev library
		JLoader::registerPrefix('BD', __DIR__ . '/libraries');

		// If the user has forced a specific connector, use it, otherwise allow JHttpFactory to decide
		$connector = $this->params->get('overrideConnector', null);

		// If the override is 'no', set to null
		if ($connector == 'no')
		{
			$connector = null;
		}

		// Instantiate our JHttp object
		$this->connector = JHttpFactory::getHttp($options, $connector);

		// Instantiate the bearer token
		$this->bearer = new BDBearer($this->params, $this->connector);

		// Get the cache controller
		$this->cache = JFactory::getCache('mod_tweetdisplayback', '');

		// Set the lifetime to match the module params
		$this->cache->setLifeTime($params->get('tweet_cache_time', 900));

		// Set whether caching is enabled
		$this->hasCaching = (bool) $params->get('tweet_cache', '1');
		$this->cache->setCaching($this->hasCaching);
	}

	/**
	 * Function to compile the data to render a formatted object displaying a Twitter feed
	 *
	 * @return  object[]  An array with the formatted tweets as objects
	 *
	 * @since   1.5
	 */
	public function compileData()
	{
		// Load the parameters
		$uname   = $this->params->get('twitterName', '');
		$list    = $this->params->get('twitterList', '');
		$count   = $this->params->get('twitterCount', 3);
		$retweet = $this->params->get('tweetRetweets', 1);
		$feed    = $this->params->get('twitterFeedType', 'user');

		// Convert the list name to a usable string for the JSON
		if ($list)
		{
			$flist = static::toAscii($list);
		}

		// Get the user info
		$this->prepareUser();

		// Check to see if we have an error
		if (isset($this->twitter['error']))
		{
			return $this->twitter;
		}

		// Set the include RT's string
		$incRT = '';

		if ($retweet == 1)
		{
			$incRT = '&include_rts=1';
		}

		// Count the number of active filters
		$activeFilters = 0;

		// Mentions
		if ($this->params->get('showMentions', 0) == 0)
		{
			$activeFilters++;
		}

		// Replies
		if ($this->params->get('showReplies', 0) == 0)
		{
			$activeFilters++;
		}

		// Retweets
		if ($retweet == 0)
		{
			$activeFilters++;
		}

		// Determine whether the feed being returned is a user, likes, or list feed
		if ($feed == 'list')
		{
			// Get the list feed
			$req = "https://api.twitter.com/1.1/lists/statuses.json?slug=$flist&owner_screen_name=$uname&include_entities=1$incRT";
		}
		elseif ($feed == 'likes')
		{
			// Get the likes feed (previously favorites)
			$req = "https://api.twitter.com/1.1/favorites/list.json?count=$count&screen_name=$uname&include_entities=1";
		}
		else
		{
			/*
			 * Get the user feed, we have to manually filter mentions, RTs and replies, so get additional tweets by multiplying $count based on the
			 * number of active filters
			 */
			if ($activeFilters == 1)
			{
				$count = $count * 3;
			}
			elseif ($activeFilters == 2)
			{
				$count = $count * 4;
			}
			elseif ($activeFilters == 3)
			{
				$count = $count * 5;
			}

			/*
			 * Determine whether the user has overridden the count parameter with a
			 * manual number of tweets to retrieve.  Override the $count variable
			 * if this is the case
			 */
			if ($this->params->get('overrideCount', 1) == 1)
			{
				$count = $this->params->get('tweetsToScan', 3);
			}

			$req = "https://api.twitter.com/1.1/statuses/user_timeline.json?count=$count&screen_name=$uname&include_entities=1";
		}

		// Fetch the decoded JSON
		try
		{
			$obj = $this->getJSON($req);
		}
		catch (RuntimeException $e)
		{
			$this->twitter['error'] = ['messages' => [$e->getMessage()]];

			return $this->twitter;
		}

		// Check if we've reached an error
		if (isset($obj->errors))
		{
			$this->twitter['error'] = ['messages' => []];

			foreach ($obj->errors as $error)
			{
				$this->twitter['error']['messages'][] = $error->message;
			}

			return $this->twitter;
		}
		// Make sure we've got an array of data
		elseif (is_array($obj))
		{
			// Store the twitter stream response object
			static::$tweets = $obj;

			// Check if $obj has data; if not, return an error
			if (is_null($obj))
			{
				// Set an error
				$this->twitter['error'] = ['messages' => [JText::_('MOD_TWEETDISPLAYBACK_ERROR_UNABLETOLOAD')]];
			}
			else
			{
				// If caching is enabled, json_encode the object and store it
				if ($this->hasCaching)
				{
					$this->getCache()->store(json_encode($obj), $this->getCacheId('tweet'), 'mod_tweetdisplayback');
				}

				// Process the filtering options and render the feed
				$this->processFiltering();

				// Flag that processing was successful
				$this->isProcessed = true;
			}
		}
		else
		{
			$this->twitter['error'] = [];
		}

		return $this->twitter;
	}

	/**
	 * Function to compile the data from cache and format the object
	 *
	 * @return  object[]  An array with the formatted tweets as objects
	 *
	 * @since   1.5
	 */
	public function compileFromCache()
	{
		// Reset the $twitter object in case we errored out previously
		$this->twitter = [];

		// Get the user info
		$this->prepareUser();

		// Check to see if we have an error or are out of hits
		if (isset($this->twitter['error']) || isset($this->twitter['hits']))
		{
			return $this->twitter;
		}

		// Retrieve the cached data and decode it
		$obj = json_decode($this->getCache()->get($this->getCacheId('tweet'), 'mod_tweetdisplayback'));

		// Check if we've reached an error
		if (isset($obj->errors))
		{
			$this->twitter['error'] = ['messages' => []];

			foreach ($obj->errors as $error)
			{
				$this->twitter['error']['messages'][] = $error->message;
			}
		}
		// Make sure we've got an array of data
		elseif (is_array($obj))
		{
			// Store the twitter stream response object
			static::$tweets = $obj;

			// Check if $obj has data; if not, return an error
			if (is_null($obj))
			{
				// Set an error
				$this->twitter['error'] = ['messages' => [JText::_('MOD_TWEETDISPLAYBACK_ERROR_UNABLETOLOAD')]];
			}
			else
			{
				// Process the filtering options and render the feed
				$this->processFiltering();

				// Flag that processing was successful
				$this->isProcessed = true;
			}
		}
		else
		{
			$this->twitter['error'] = [];
		}

		return $this->twitter;
	}

	/**
	 * Get the cache adapter
	 *
	 * @return  JCacheController
	 *
	 * @since   4.0
	 */
	public function getCache()
	{
		return $this->cache;
	}

	/**
	 * Get the cache ID for a cache type, generating it if it doesn't exist
	 *
	 * @return  string
	 *
	 * @since   4.0
	 * @throws  InvalidArgumentException
	 */
	public function getCacheId($type)
	{
		if (!in_array($type, ['tweet', 'user']))
		{
			throw new InvalidArgumentException('Invalid cache ID type');
		}

		// Generate the cache ID if needed - simply set the data type and the module ID to allow unique caching per module
		if (empty($this->cacheIds[$type]))
		{
			$this->cacheIds[$type] = "mod_tweetdisplayback_$type-" . $this->moduleId;
		}

		return $this->cacheIds[$type];
	}

	/**
	 * Function to fetch a JSON feed
	 *
	 * @param   string  $req  The URL of the feed to load
	 *
	 * @return  object  The fetched JSON query
	 *
	 * @since   1.0
	 * @throws  RuntimeException
	 */
	public function getJSON($req)
	{
		// Get the data
		try
		{
			$headers = [
				'Authorization' => "Bearer {$this->bearer->token}"
			];

			$response = $this->connector->get($req, $headers);
		}
		catch (Exception $e)
		{
			return null;
		}

		// Return the decoded response body
		return json_decode($response->body);
	}

	/**
	 * Function to fetch the user JSON and render it
	 *
	 * @return  void
	 *
	 * @since   1.5
	 */
	protected function prepareUser()
	{
		// Load the parameters
		$uname = $this->params->get('twitterName', '');
		$list  = $this->params->get('twitterList', '');
		$feed  = $this->params->get('twitterFeedType', 'user');

		// Initialize object containers
		$this->twitter['header'] = new stdClass;
		$this->twitter['footer'] = new stdClass;
		$this->twitter['tweets'] = new stdClass;

		// Convert the list name to a usable string for the URL
		if ($list)
		{
			$flist = static::toAscii($list);
		}

		// Retrieve data from Twitter if the header is enabled
		if ($this->params->get('headerDisplay', 1) == 1)
		{
			$fetchData = function () use ($uname)
			{
				$req = "https://api.twitter.com/1.1/users/show.json?screen_name=$uname";

				try
				{
					$obj = $this->getJSON($req);
				}
				catch (RuntimeException $e)
				{
					$this->twitter['error'] = ['messages' => [$e->getMessage()]];

					return;
				}

				// Check if we've reached an error
				if (isset($obj->errors))
				{
					$this->twitter['error'] = ['messages' => []];

					foreach ($obj->errors as $error)
					{
						$this->twitter['error']['messages'][] = $error->message;
					}

					return;
				}
				// Check that we have the JSON, otherwise set an error
				elseif (!$obj)
				{
					$this->twitter['error'] = [];

					return;
				}

				// Store the user profile response object so it can be accessed (for advanced use)
				static::$user = $obj;

				// If caching is enabled, json_encode the object and store it
				if ($this->hasCaching)
				{
					$this->getCache()->store(json_encode($obj), $this->getCacheId('user'), 'mod_tweetdisplayback');
				}

				return $obj;
			};

			if ($this->hasCaching)
			{
				$obj = json_decode($this->getCache()->get($this->getCacheId('user'), 'mod_tweetdisplayback'));

				// Check if cache has expired; if so we need to re-compile
				if ($obj === null)
				{
					$obj = $fetchData();

					// If we have a null return from here we've hit a fatal error, the message is set in the Closure so we can just return here
					if ($obj === null)
					{
						return;
					}
				}
			}
			else
			{
				$obj = $fetchData();

				// If we have a null return from here we've hit a fatal error, the message is set in the Closure so we can just return here
				if ($obj === null)
				{
					return;
				}
			}

			/*
			 * Header info
			 */

			if ($this->params->get('headerUser', 1) == 1)
			{
				// Show the real name or the username
				$displayName = $this->params->get('headerName', 1) == 1 ? $obj->name : $uname;

				$linkAttribs = ['rel' => 'nofollow', 'target' => '_blank'];

				$intent = '';

				if ($this->params->get('bypassIntent', '0') == 0)
				{
					$intent = 'intent/user?screen_name=';
					unset($linkAttribs['target']);
				}

				$userURL = "https://twitter.com/$intent" . $uname;

				$this->twitter['header']->user = JHtml::_('link', $userURL, $displayName, ['rel' => 'nofollow']);

				if ($this->params->get('tweetUserSeparator', ' '))
				{
					$this->twitter['header']->user .= $this->params->get('tweetUserSeparator', ' ');
				}

				// Append the list name if being pulled
				if ($feed == 'list')
				{
					$this->twitter['header']->user .= JHtml::_(
						'link',
						"https://twitter.com/$uname/$flist",
						JText::sprintf('MOD_TWEETDISPLAYBACK_HEADER_LIST_LINK', $list),
						['rel' => 'nofollow']
					);
				}
			}

			// Show the bio
			if ($this->params->get('headerBio', 1) == 1)
			{
				$this->twitter['header']->bio = $obj->description;
			}

			// Show the location
			if ($this->params->get('headerLocation', 1) == 1)
			{
				$this->twitter['header']->location = $obj->location;
			}

			// Show the user's URL
			if ($this->params->get('headerWeb', 1) == 1)
			{
				$this->twitter['header']->web = JHtml::_('link', $obj->url, $obj->url, ['rel' => 'nofollow', 'target' => '_blank']);
			}

			// Get the profile image URL from the object
			$avatar = $obj->profile_image_url_https;

			// Switch from the normal size avatar (48px) to the large one (73px)
			$avatar = str_replace('normal.jpg', 'bigger.jpg', $avatar);

			$this->twitter['header']->avatar = JHtml::_('image', $avatar, $uname);
		}

		/*
		 * Footer info
		 */

		// Display the Follow button
		if ($this->params->get('footerFollowLink', 1) == 1)
		{
			// Don't display for a list feed
			if ($feed != 'list')
			{
				$followParams = [
					'screen_name'      => $uname,
					'lang'             => substr(JFactory::getLanguage()->getTag(), 0, 2),
					'show_screen_name' => (bool) $this->params->get('footerFollowUser', 1),
					'show_count'       => (bool) $this->params->get('footerFollowCount', '1')
				];

				$iframe = JHtml::_(
					'iframe',
					'https://platform.twitter.com/widgets/follow_button.html?' . http_build_query($followParams, null, '&amp;'),
					'follow-user-' . $this->moduleId,
					['allowtransparency' => true, 'frameborder' => 0, 'scrolling' => 'no', 'style' => 'width: 300px; height: 20px;']
				);

				$this->twitter['footer']->follow_me = '<div class="TDB-footer-follow-link">' . $iframe . '</div>';
			}
		}
	}

	/**
	 * Function to render the Twitter feed into a formatted object
	 *
	 * @return  void
	 *
	 * @since   2.0
	 */
	protected function processFiltering()
	{
		// Initialize
		$count          = $this->params->get('twitterCount', 3);
		$showMentions   = $this->params->get('showMentions', 0);
		$showReplies    = $this->params->get('showReplies', 0);
		$showRetweets   = $this->params->get('tweetRetweets', 1);
		$numberOfTweets = $this->params->get('twitterCount', 3);
		$feedType       = $this->params->get('twitterFeedType', 'user');
		$obj            = static::$tweets;
		$i              = 0;

		// Process the feed
		foreach ($obj as $o)
		{
			if ($count > 0)
			{
				// Check if we have all of the items we want
				if ($i < $numberOfTweets)
				{
					// If we aren't filtering, just render the item
					if (($showMentions == 1 && $showReplies == 1 && $showRetweets == 1) || ($feedType == 'list' || $feedType == 'likes'))
					{
						$this->processItem($o, $i);

						// Modify counts
						$count--;
						$i++;

						continue;
					}

					// We're filtering, the fun starts here
					else
					{
						// Set variables
						// Tweets which contains a @reply
						$tweetContainsReply = $o->in_reply_to_user_id != null;

						// Tweets which contains a @mention and/or @reply
						$tweetContainsMentionAndOrReply = $o->entities->user_mentions != null;

						// Tweets which are a retweet
						$tweetIsRetweet = isset($o->retweeted_status);

						/*
						 * Check if a reply tweet contains mentions
						 * NOTE: Works only for tweets where there is also a reply, since reply is at
						 * the position ['0'] and mentions begin at ['1'].
						 */
						if (isset($o->entities->user_mentions['1']))
						{
							$replyTweetContainsMention = $o->entities->user_mentions['1'];
						}
						else
						{
							$replyTweetContainsMention = '0';
						}

						// Tweets with only @reply
						$tweetOnlyReply = $tweetContainsReply && $replyTweetContainsMention == '0';

						// Tweets which contains @mentions or @mentions+@reply
						$tweetContainsMention = $tweetContainsMentionAndOrReply && !$tweetOnlyReply;

						// Filter retweets
						if ($showRetweets == 0)
						{
							if (!$tweetIsRetweet)
							{
								$this->processItem($o, $i);

								// Modify counts
								$count--;
								$i++;

								continue;
							}
						}

						// Filter @mentions and @replies, leaving retweets unchanged
						if ($showMentions == 0 && $showReplies == 0)
						{
							if (!$tweetContainsMentionAndOrReply || (isset($o->retweeted_status) && $showRetweets == 1))
							{
								$this->processItem($o, $i);

								// Modify counts
								$count--;
								$i++;

								continue;
							}
						}

						// Filtering only @mentions or @replies
						else
						{
							// Filter @mentions only leaving @replies and retweets unchanged
							if ($showMentions == 0)
							{
								if (!$tweetContainsMention || (isset($o->retweeted_status) && $showRetweets == 1))
								{
									$this->processItem($o, $i);

									// Modify counts
									$count--;
									$i++;

									continue;
								}
							}

							// Filter @replies only (including @replies with @mentions) leaving retweets unchanged
							if ($showReplies == 0)
							{
								if (!$tweetContainsReply)
								{
									$this->processItem($o, $i);

									// Modify counts
									$count--;
									$i++;

									continue;
								}
							}

							// Somehow, we got this far; process the tweet
							if ($showMentions == 1 && $showReplies == 1 && $showRetweets == 1)
							{
								// No filtering required
								$this->processItem($o, $i);

								// Modify counts
								$count--;
								$i++;

								continue;
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Function to process the Twitter feed into a formatted object
	 *
	 * @param   object   $o  The item within the JSON feed
	 * @param   integer  $i  Iteration of processFiltering
	 *
	 * @return  void
	 *
	 * @since   2.0
	 */
	protected function processItem($o, $i)
	{
		// Set variables
		$tweetRTCount = $this->params->get('tweetRetweetCount', 1);

		// Initialize a new object
		$this->twitter['tweets']->$i = new stdClass;

		// Check if the item is a retweet, and if so gather data from the retweeted_status datapoint
		if (isset($o->retweeted_status))
		{
			// Retweeted user
			$tweetedBy = $o->retweeted_status->user->screen_name;
			$avatar    = $o->retweeted_status->user->profile_image_url_https;
			$text      = $o->retweeted_status->text;
			$urls      = $o->retweeted_status->entities->urls;
			$RTs       = $o->retweeted_status->retweet_count;
			$created   = JText::_('MOD_TWEETDISPLAYBACK_RETWEETED');

			if (isset($o->retweeted_status->entities->media))
			{
				$media = $o->retweeted_status->entities->media;
			}
		}
		else
		{
			// User
			$tweetedBy = $o->user->screen_name;
			$avatar    = $o->user->profile_image_url_https;
			$text      = $o->text;
			$urls      = $o->entities->urls;
			$RTs       = $o->retweet_count;
			$created   = '';

			if (isset($o->entities->media))
			{
				$media = $o->entities->media;
			}
		}

		$this->twitter['tweets']->$i->created = $created;

		// Generate the object with the user data
		if ($this->params->get('tweetName', 1) == 1)
		{
			$intent = $this->params->get('bypassIntent', '0') == 1 ? '' : 'intent/user?screen_name=';

			$userURL = "https://twitter.com/$intent" . $tweetedBy;

			$this->twitter['tweets']->$i->user = JHtml::_('link', $userURL, $tweetedBy, ['rel' => 'nofollow']);

			if ($this->params->get('tweetUserSeparator', ' '))
			{
				$this->twitter['tweets']->$i->user .= $this->params->get('tweetUserSeparator', ' ');
			}
		}

		$this->twitter['tweets']->$i->avatar = JHtml::_('image', $avatar, $tweetedBy, ['width' => 32]);
		$this->twitter['tweets']->$i->text   = $text;

		// Make regular URLs in tweets a link
		foreach ($urls as $url)
		{
			$displayUrl = isset($url->display_url) ? $url->display_url : $url->url;

			// We need to check to verify that the URL has the protocol, just in case
			$link = (strpos($url->url, 'http') !== 0 ? 'http://' : '') . $url->url;

			$this->twitter['tweets']->$i->text = str_replace(
				$url->url,
				JHtml::_('link', $link, $displayUrl, ['target' => '_blank', 'rel' => 'nofollow']),
				$this->twitter['tweets']->$i->text
			);
		}

		// Make media URLs in tweets a link
		if (isset($media))
		{
			foreach ($media as $image)
			{
				$imageUrl = isset($image->display_url) ? $image->display_url : $image->url;

				$this->twitter['tweets']->$i->text = str_replace(
					$image->url,
					JHtml::_('link', $image->url, $imageUrl, ['target' => '_blank', 'rel' => 'nofollow']),
					$this->twitter['tweets']->$i->text
				);
			}
		}

		/*
		 * Info below is specific to each tweet, so it isn't checked against a retweet
		 */

		// Display the time the tweet was created
		if ($this->params->get('tweetCreated', 1) == 1)
		{
			// Determine whether to display the time as a relative or static time
			if ($this->params->get('tweetRelativeTime', 1) == 1)
			{
				$displayTime= JHtml::_('date.relative', JFactory::getDate($o->created_at, 'UTC'), null, JFactory::getDate('now', 'UTC'));
			}
			else
			{
				$displayTime = JHtml::_('date', $o->created_at);
			}

			$this->twitter['tweets']->$i->created .= JHtml::_(
				'link',
				"https://twitter.com/{$o->user->screen_name}/status/{$o->id_str}",
				$displayTime,
				['target' => '_blank', 'rel' => 'nofollow']
			);
		}

		// Display the tweet source
		if ($this->params->get('tweetSource', 1) == 1)
		{
			$this->twitter['tweets']->$i->created .= JText::sprintf('MOD_TWEETDISPLAYBACK_VIA', $o->source);
		}

		// Display the location the tweet was made from if set
		if (($this->params->get('tweetLocation', 1) == 1) && (isset($o->place->full_name)))
		{
			$this->twitter['tweets']->$i->created .= JText::sprintf(
				'MOD_TWEETDISPLAYBACK_FROM',
				JHtml::_(
					'link',
					"https://maps.google.com/maps?q={$o->place->full_name}",
					$o->in_reply_to_screen_name,
					['target' => '_blank', 'rel' => 'nofollow']
				)
			);
		}

		// If the tweet is a reply, display a link to the tweet it's in reply to
		if ((($o->in_reply_to_screen_name) && ($o->in_reply_to_status_id_str)) && $this->params->get('tweetReplyLink', 1) == 1)
		{
			$this->twitter['tweets']->$i->created .= JText::sprintf(
				'MOD_TWEETDISPLAYBACK_IN_REPLY_TO',
				JHtml::_(
					'link',
					"https://twitter.com/{$o->in_reply_to_screen_name}/status/{$o->in_reply_to_status_id_str}",
					$o->in_reply_to_screen_name,
					['rel' => 'nofollow']
				)
			);
		}

		// Display a separator bullet if there's a tweet time/source and a retweet count
		if ((($this->params->get('tweetSource', 1) == 1)
			|| (($this->params->get('tweetLocation', 1) == 1) && (isset($o->place->full_name)))
			|| ((($o->in_reply_to_screen_name) && ($o->in_reply_to_status_id_str)) && $this->params->get('tweetReplyLink', 1) == 1))
			&& (($tweetRTCount == 1) && ($RTs >= 1)))
		{
			$this->twitter['tweets']->$i->created .= ' &bull; ';
		}

		// Display the number of times the tweet has been retweeted
		if (($tweetRTCount == 1) && ($RTs >= 1))
		{
			$this->twitter['tweets']->$i->created .= JText::plural('MOD_TWEETDISPLAYBACK_RETWEETS', $RTs);
		}

		// Display Twitter Actions
		if ($this->params->get('tweetReply', 1) == 1)
		{
			$replyAction = JHtml::_(
				'link',
				"https://twitter.com/intent/tweet?in_reply_to={$o->id_str}",
				'',
				['title' => JText::_('MOD_TWEETDISPLAYBACK_INTENT_REPLY'), 'rel' => 'nofollow']
			);

			$retweetAction = JHtml::_(
				'link',
				"https://twitter.com/intent/tweet?retweet={$o->id_str}",
				'',
				['title' => JText::_('MOD_TWEETDISPLAYBACK_INTENT_RETWEET'), 'rel' => 'nofollow']
			);

			$likeAction = JHtml::_(
				'link',
				"https://twitter.com/intent/tweet?like={$o->id_str}",
				'',
				['title' => JText::_('MOD_TWEETDISPLAYBACK_INTENT_LIKE'), 'rel' => 'nofollow']
			);

			$this->twitter['tweets']->$i->actions = "<span class=\"TDB-action TDB-reply\">$replyAction</span>";
			$this->twitter['tweets']->$i->actions .= "<span class=\"TDB-action TDB-retweet\">$retweetAction</span>";
			$this->twitter['tweets']->$i->actions .= "<span class=\"TDB-action TDB-like\">$likeAction</span>";
		}

		// If set, convert user and hash tags into links
		if ($this->params->get('tweetLinks', 1) == 1)
		{
			foreach ($o->entities->user_mentions as $mention)
			{
				$intent = $this->params->get('bypassIntent', '0') == 1 ? '' : 'intent/user?screen_name=';

				$mentionURL = "https://twitter.com/$intent" . $mention->screen_name;

				$this->twitter['tweets']->$i->text = str_ireplace(
					'@' . $mention->screen_name,
					JHtml::_('link', $mentionURL, '@' . $mention->screen_name, ['class' => 'userlink', 'rel' => 'nofollow']),
					$this->twitter['tweets']->$i->text
				);
			}

			foreach ($o->entities->hashtags as $hashtag)
			{
				$this->twitter['tweets']->$i->text = str_ireplace(
					'#' . $hashtag->text,
					JHtml::_(
						'link',
						'https://twitter.com/search?q=%23' . $hashtag->text,
						'#' . $hashtag->text,
						['class' => 'hashlink', 'rel' => 'nofollow', 'target' => '_blank']
					),
					$this->twitter['tweets']->$i->text
				);
			}
		}
	}

	/**
	 * Function to convert a formatted list name into its URL equivalent
	 *
	 * @param   string  $list  The user inputted list name
	 *
	 * @return  string  The list name converted
	 *
	 * @since   1.6
	 */
	public static function toAscii($list)
	{
		$clean = preg_replace("/[^a-z'A-Z0-9\/_|+ -]/", '', $list);
		$clean = strtolower(trim($clean, '-'));
		$list  = preg_replace("/[\/_|+ -']+/", '-', $clean);

		return $list;
	}
}
