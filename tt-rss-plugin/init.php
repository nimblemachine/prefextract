<?php
class Learnfilter extends Plugin {
	private $link;
	private $host;
	private $LFcache = array();
	private $maxCacheSize = 500;

	function init($host) {
		$this->link = $host->get_link();
		$this->host = $host;

		//$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this);

		$this->LFcache = json_decode($this->host->get($this, "Learnfilter_cache"), true);
	}

	function __destruct() {
		$this->host->set($this, "Learnfilter_cache", json_encode($this->LFcache));
	}

	function about() {
		return array(0.1,
				"Filter articles using personal ratings of articles or tags extrated from them.",
				"Jiří Procházka");
	}
	function save() {
		$learnfilter_url = db_escape_string($this->link, $_POST["learnfilter_url"]);
		$this->host->set($this, "Learnfilter_URL", $learnfilter_url);
		echo "Learnfilter URL set to $learnfilter_url<br/>";
		$learnfilter_threshold = db_escape_string($this->link, $_POST["learnfilter_threshold"]);
		$this->host->set($this, "Learnfilter_threshold", $learnfilter_threshold);
		echo "Learnfilter threshold set to $learnfilter_threshold<br/>";
		if($_POST["learnfilter_clearcache"] == 'on')
		{
			$this->host->set($this, "Learnfilter_cache", json_encode(array()));
			$this->LFcache = array();
			echo "Cache cleared.";
		}
	}
	function get_js() {
		return file_get_contents(dirname(__FILE__) . "/learnfilter.js");
	}
	function getUID() {
		return 'learnfilter:'.$_SESSION['uid'];
	}
	function getURL() {
		$url = $this->host->get($this, "Learnfilter_URL");
		if(strncmp($url, "http", 4) != 0) {
			$url = "http://localhost:18967/";
		}
		return $url;
	}
	function getThreshold() {
		$t = (float)$this->host->get($this, "Learnfilter_threshold");
		if($t > -0.1) {
			$t = -0.5;
		}
		return $t;
	}

	function urlsafe_b64encode($data) {
		//return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
		return strtr(base64_encode($data), '+/', '-_');
	}
	function urlsafe_b64decode($data) {
		//return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
		return base64_decode(strtr($data, '-_', '+/'));
	}
	function html2txt($document){
		$rep = array('@</p>@i', '@<br>@i', '@</br>@i', '@<br />@i', '@<br/>@i');
		$doc2 = preg_replace($rep, "\n", $document);
		$search = array('@<script[^>]*?>.*?</script>@si',  // Strip out javascript
					   '@<[\/\!]*?[^<>]*?>@si',            // Strip out HTML tags
					   '@<style[^>]*?>.*?</style>@siU',    // Strip style tags properly
					   '@<![\s\S]*?--[ \t\n\r]*>@'         // Strip multi-line comments including CDATA
		);
		$text = preg_replace($search, '', $doc2);
		$text = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
		return $text;
	} 
	// $_SESSION['uid'] or $_SESSION['name'] as user ID

	function hook_render_article($article) {
		return $this->hook_render_article_cdm($article);
	}
	function hook_render_article_cdm($article) {
		$acontent = "";
		$uid = $this->getUID();
		$content = $this->html2txt($article["title"]);
		$content .= " . \n".$this->html2txt($article["content"]);
		$data = json_encode(array("user" => $uid, "actionGetRating" => true, "text" => $content));
		$datahash = md5($uid.$article["title"]);

		$output = "";
		//$_SESSION["plugin_storage"] = false;
		//$acontent .= "SX ".$this->host->get($this, "Learnfilter_cacheInvalidate")."<br>";
		if(!array_key_exists($datahash, $this->LFcache)) {
			$ch = curl_init($this->getURL());
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array("data" => $data)));
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($ch, CURLOPT_POST, 1);
			$output = curl_exec($ch);
			curl_close($ch);
			if($output) {
				if(count($this->LFcache) >= $this->maxCacheSize) {
					unset($this->LFcache[array_rand($this->LFcache)]);
				}
				$this->LFcache[$datahash] = $output;
			}
		} else {
			$output = $this->LFcache[$datahash];
		}

		$outdata = json_decode($output, true);
		if($outdata) {
			if($outdata["rating"][0] < $this->getThreshold()) {
				$acontent .= "<p style='font-size: x-small; color: #555;'>LF [filtered] (<a href='#' onClick='learnfilterShow(\"LFilteredArticle-".$article['id']."\");'>show</a>)</p>".
					"<div id='LFilteredArticle-".$article['id']."' style='display: none;'>\n";
			} else {
				$acontent .= "<div>\n";
			}
			$acontent .= "<p style='font-size: small; color: #555;'>LF keywords: ";
			$kws = $outdata["keywords"] ? $outdata["keywords"] : array();
			$ratedKws = $outdata["rating"][1];
			foreach($kws as $kw) {
				if($ratedKws && array_key_exists($kw, $ratedKws)) {
					if($ratedKws[$kw] >= 3.0) $acontent .= "<b>";
					if($ratedKws[$kw] <= -3.0) $acontent .= "<i>";
				}
				$acontent .= $kw;
				if($ratedKws && array_key_exists($kw, $ratedKws)) {
					if($ratedKws[$kw] >= 3.0) $acontent .= "</b>";
					if($ratedKws[$kw] <= -3.0) $acontent .= "</i>";
				}
				$acontent .= "(<a href='#' onClick='learnfilterModRating(\"".addslashes($kw)."\",1.0, \"".$datahash."\");' title='increase rating'>+</a>/".
					"<a href='#' onClick='learnfilterModRating(\"".addslashes($kw)."\",-1.0, \"".$datahash."\");' title='decrease rating'>-</a>), ";
			}
			if(count($kws) > 0) { 
				$acontent .= "[all](<a href='#' onClick='learnfilterModRating(\"".addslashes(implode("_",$kws))."\",1.0/".count($kws).", \"".$datahash."\");' title='increase rating'>+</a>/"
					."<a href='#' onClick='learnfilterModRating(\"".addslashes(implode("_",$kws))."\",-1.0/".count($kws).", \"".$datahash."\");' title='decrease rating'>-</a>)";
			}
			$acontent .= " <span style='color: #BBB;' title='rating'>".$outdata["rating"][0]."</span></p>\n".$article["content"]."</div>";
			$article["content"] = $acontent;
		}
		//$article["content"] = htmlspecialchars(print_r($article, true))."<br />".$acontent;

		/*$article["content"] = htmlspecialchars(print_r($outdata, true))."<br />\n".htmlspecialchars($data)."<br />\n".htmlspecialchars($output)."<br />\narticle id: ".$article["id"]."<br />\n".
			"<p style='color: blue; display: block;'>".htmlspecialchars(print_r($article, true))."<br />".htmlspecialchars($this->html2txt($article["content"]))."</p>" . 
			$article["content"];
		 */
		return $article;
	}

	function modRating() {
		$uid = $this->getUID();
		$mod = $_POST["mod"];
		$value = $_POST["value"];
		$ahash = $_POST["hash"];
		//$this->LFcache = json_decode($this->host->get($this, "Learnfilter_cache"), true);
		//unset($this->LFcache[$ahash]);
		//$this->host->set($this, "Learnfilter_cache", json_encode($this->LFcache));
		$this->host->set($this, "Learnfilter_cacheInvalidate", $ahash);
		$_SESSION["plugin_storage"] = false;
		$modArray = explode("_",$mod);
		$modArray2 = array();
		foreach($modArray as $x) {
			$modArray2[$x] = (float)$value;
		}
		$data = json_encode(array("user" => $uid, "actionModRatings" => true, "modRatings" => $modArray2));

		$ch = curl_init($this->getURL());
		$encoded = '';
		foreach(array("data" => $data) as $name => $value) {
		  $encoded .= urlencode($name).'='.urlencode($value).'&';
		}
		// chop off last ampersand
		$encoded = substr($encoded, 0, strlen($encoded)-1);
		curl_setopt($ch, CURLOPT_POSTFIELDS,  $encoded);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		$o = curl_exec($ch);
		curl_close($ch);
		print $o;
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		print "<div dojoType=\"dijit.layout.AccordionPane\" title=\"".__("Learnfilter")."\">";

		print "<br/>";

		$learnfilter_url = $this->getURL();
		$learnfilter_threshold = $this->getThreshold();
		print "<form dojoType=\"dijit.form.Form\">";

		print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
			evt.preventDefault();
		if (this.validate()) {
			console.log(dojo.objectToQuery(this.getValues()));
			new Ajax.Request('backend.php', {
parameters: dojo.objectToQuery(this.getValues()),
onComplete: function(transport) {
notify_info(transport.responseText);
}
});
}
</script>";

		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"op\" value=\"pluginhandler\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"method\" value=\"save\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"plugin\" value=\"learnfilter\">";
		print "<table width=\"100%\" class=\"prefPrefsList\">";
		print "<tr><td width=\"40%\">".__("Learnfilter prefextract service URL")."</td>";
		print "<td class=\"prefValue\"><input dojoType=\"dijit.form.ValidationTextBox\" required=\"1\" name=\"learnfilter_url\" regExp='^(http|https)://.*' value=\"$learnfilter_url\"></td></tr>";
		print "<tr><td width=\"40%\">".__("Learnfilter rating threshold")."</td>";
		print "<td class=\"prefValue\"><input dojoType=\"dijit.form.ValidationTextBox\" required=\"1\" name=\"learnfilter_threshold\" regExp='^-[0-9]+\.[0-9]*$' value=\"$learnfilter_threshold\"></td></tr>";
		print "<tr><td width=\"40%\">".__("Clear Learnfilter cache")."</td>";
		print "<td class=\"prefValue\"><input dojoType=\"dijit.form.CheckBox\" name=\"learnfilter_clearcache\" /></td></tr>";
		//print "<tr><td width=\"40%\">".__("Learnfilter API Key")."</td>";
		//print "<td class=\"prefValue\"><input dojoType=\"dijit.form.ValidationTextBox\" required=\"1\" name=\"learnfilter_api\" value=\"$learnfilter_api\"></td></tr>";
		print "</table>";
		print "<p><button dojoType=\"dijit.form.Button\" type=\"submit\">".__("Save")."</button>";

		print "</form>";

		print "</div>"; #pane

	}

}
?>
