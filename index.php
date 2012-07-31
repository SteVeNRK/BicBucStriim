<?php
// Copyight 2012 Rainer Volz
// Licensed under MIT License, see README.MD/License

require_once 'lib/Slim/Slim.php';
require_once 'lib/Slim/Views/TwigView.php';
TwigView::$twigDirectory = dirname(__FILE__) . '/lib/Twig';
TwigView::$twigExtensions = array(
    'Twig_Extensions_Slim'
);

require_once 'lib/BicBucStriim/bicbucstriim.php';
require_once 'lib/BicBucStriim/opds_generator.php';
require_once 'lib/BicBucStriim/langs.php';

# Allowed languages, i.e. languages with translations
$allowedLangs = array('de','en','fr');
# Fallback language if the browser prefers other than the allowed languages
$fallbackLang = 'en';
# Application Name
$appname = 'BicBucStriim';
# App version
$appversion = '0.9.0';
# Cookie name for global download protection
define('GLOBAL_DL_COOKIE', 'glob_dl_access');
# Cookie name for admin access
define('ADMIN_COOKIE', 'admin_access');
# Admin password
define('ADMIN_PW', 'admin_pw');
# Calibre library path
define('CALIBRE_DIR', 'calibre_dir');
# Global download choice
define('GLOB_DL_CHOICE', 'glob_dl_choice');
# Global download password
define('GLOB_DL_PASSWORD', 'glob_dl_password');
# BicBucStriim DB version
define('DB_VERSION', 'db_version');

# Init app and routes
$app = new Slim(array(
	'debug' => true,
	'log.enabled' => true, 
	#'log.writer' => new Slim_LogFileWriter(fopen('./data/bbs.log','a')),
	'log.level' => 4,
	'view' => new TwigView(),
	));

# Init app globals
$globalSettings = array();
$globalSettings['appname'] = $appname;
$globalSettings['version'] = $appversion;
$globalSettings['sep'] = ' :: ';
$globalSettings['lang'] = getUserLang($allowedLangs, $fallbackLang);
if ($globalSettings['lang'] == 'de')
	$globalSettings['langa'] = $langde;
elseif ($globalSettings['lang'] == 'fr')
	$globalSettings['langa'] = $langfr;
else
	$globalSettings['langa'] = $langen;

# TODO: 30 als Standardwert
$globalSettings['pagentries'] = 2;

# Add globals from DB
$bbs = new BicBucStriim();
if ($bbs->dbOk()) {
	$app->getLog()->debug("config db found");
	$we_have_config = true;
	$css = $bbs->configs();
	foreach ($css as $config) {
		switch ($config->name) {
			case ADMIN_PW:
				$globalSettings[ADMIN_PW] = $config->val;
				break;			
			case CALIBRE_DIR:
				$globalSettings[CALIBRE_DIR] = $config->val;
				break;
			case DB_VERSION:
				$globalSettings[DB_VERSION] = $config->val;
				break;
			case GLOB_DL_PASSWORD:
				$globalSettings[GLOB_DL_PASSWORD] = $config->val;
				break;
			case GLOB_DL_CHOICE:
				$globalSettings[GLOB_DL_CHOICE] = $config->val;
				break;
			default:
				$app->getLog()->warn(join('',array('Unknown configuration, name: ',
					$config->name,', value: ',$config->val)));	
		}
	}
	$app->getLog()->debug("config loaded");
} else {
	$app->getLog()->debug("no config db found");
	$we_have_config = false;
}



# Init routes
$app->notFound('myNotFound');
$app->get('/', 'htmlCheckConfig', 'main');
$app->get('/admin/', 'admin');
$app->post('/admin/', 'admin_change_json');
$app->get('/admin/access/', 'admin_is_protected');
$app->post('/admin/access/check/', 'admin_checkaccess');
$app->get('/admin/error/:id', 'admin_error');
$app->get('/titles/', 'htmlCheckConfig', 'titles');
$app->get('/titles/:id/', 'htmlCheckConfig','title');
$app->get('/titles/:id/showaccess/', 'htmlCheckConfig', 'showaccess');
$app->post('/titles/:id/checkaccess/', 'htmlCheckConfig', 'checkaccess');
$app->get('/titles/:id/cover/', 'htmlCheckConfig', 'cover');
$app->get('/titles/:id/file/:file', 'htmlCheckConfig', 'book');
$app->get('/titles/:id/thumbnail/', 'htmlCheckConfig', 'thumbnail');
$app->get('/titleslist/:id/', 'htmlCheckConfig', 'titlesSlice');
$app->get('/authors/', 'htmlCheckConfig', 'authors');
$app->get('/authors/:id/', 'htmlCheckConfig', 'author');
$app->get('/authorslist/:id/', 'htmlCheckConfig', 'authorsSlice');
$app->get('/tags/', 'htmlCheckConfig', 'tags');
$app->get('/tags/:id/', 'htmlCheckConfig', 'tag');
$app->get('/tagslist/:id/', 'htmlCheckConfig', 'tagsSlice');
$app->get('/opds/', 'opdsCheckConfig', 'opdsRoot');
$app->get('/opds/newest/', 'opdsCheckConfig', 'opdsNewest');
$app->get('/opds/titleslist/:id/', 'opdsCheckConfig', 'opdsByTitle');
$app->get('/opds/authorslist/', 'opdsCheckConfig', 'opdsByAuthorInitial');
$app->get('/opds/authorslist/:initial/', 'opdsCheckConfig', 'opdsByAuthorNamesForInitial');
$app->get('/opds/authorslist/:initial/:id/', 'opdsCheckConfig', 'opdsByAuthor');
$app->get('/opds/tagslist/:id/', 'opdsCheckConfig', 'opdsByTag');
$app->run();


/**
 * Check the configuration DB and open it
 * @return int 0 = ok
 *             1 = no config db
 *             2 = no calibre library path defined (after installation scenario)
 *             3 = error while opening the calibre db 
 */
function check_config() {
	global $we_have_config, $bbs, $app, $globalSettings;

	$app->getLog()->debug('check_config started');
	# No config --> error
	if (!$we_have_config) {
		$app->getLog()->error('check_config: No configuration found');
		return(1);
	}

	# 'After installation' scenario: here is a config DB but no valid connection to Calibre
	if ($we_have_config && $globalSettings[CALIBRE_DIR] === '') {
		if ($app->request()->isPost() && $app->request()->getResourceUri() === '/admin/') {
			# let go through
		} else {
			$app->getLog()->warn('check_config: Calibre library path not configured, showing admin page.');	
			return(2);
		}
	}

	# Setup the connection to the Calibre metadata db
	$clp = $globalSettings[CALIBRE_DIR].'/metadata.db';
	$bbs->openCalibreDB($clp);
	if (!$bbs->libraryOk()) {
		$app->getLog()->error('check_config: Exception while opening metadata db '.$clp.'. Showing admin page.');	
		return(3);
	} 	
	$app->getLog()->debug('check_config ended');
	return(0);
}

# Check if the configuration is valid:
# - If there is no bbs db --> show error
function htmlCheckConfig() {
	global $app, $globalSettings;

	$result = check_config();

	# No config --> error
	if ($result === 1) {
		$app->render('error.html', array(
			'page' => mkPage($globalSettings['langa']['error']), 
			'title' => $globalSettings['langa']['error'], 
			'error' => $globalSettings['langa']['no_config']));
		return;
	} elseif ($result === 2) {
		# After installation, no calibre dir defined, goto admin page
		$app->redirect($app->request()->getRootUri().'/admin');
		return;
	} elseif ($result === 3) {
		# Calibre dir wrong? Goto admin page
		$app->redirect($app->request()->getRootUri().'/admin');
		return;
	} 
}

function myNotFound() {
	global $app, $globalSettings;
	$app->render('error.html', array(
		'page' => mkPage($globalSettings['langa']['not_found1']), 
		'title' => $globalSettings['langa']['not_found1'], 
		'error' => $globalSettings['langa']['not_found2']));
}

# Index page -> /
function main() {
	global $app, $globalSettings, $bbs;

	$books = $bbs->last30Books();
	$app->render('index_last30.html',array(
		'page' => mkPage($globalSettings['langa']['dl30'],1), 
		'books' => $books));	
}

# Admin page -> /admin/
function admin() {
	global $app, $globalSettings, $bbs;

	$app->render('admin.html',array(
		'page' => mkPage($globalSettings['langa']['admin'])));
}

# Is the key in globalSettings?
function has_global_setting($key) {
	return (isset($globalSettings[$key]) && !empty($globalSettings[$key]));
}

# Is there a valid - existing - Calibre directory?
function has_valid_calibre_dir() {
	return (has_global_setting(CALIBRE_DIR) && 
		BicBucStriim::checkForCalibre($globalSettings[CALIBRE_DIR]));
}

/*
Check for admin permissions. If no admin password is defined
everyone has admin permissions.
 */
function is_admin() {
	if (empty($globalSettings[ADMIN_PW]))
		return true;
	else {
		$admin_cookie = $app->getCookie(ADMIN_ACCESS_COOKIE);
		if (isset($admin_cookie))
			return true;
		else
			return false;
	}	
}

# Processes changes in the admin page -> POST /admin/
function admin_change_json() {
	global $app, $globalSettings, $bbs;
	$app->getLog()->debug('admin_change: started');	
	# Check access permission
	if (!is_admin()) {
		$app->getLog()->warn('admin_change: no admin permission');	
		$app->notFound();
	}
	$nconfigs = array();
	$req_configs = $app->request()->post();
	$errors = array();

	## Check for consistency - calibre directory
	# Calibre dir is still empty and no change in sight --> error
	if (!has_valid_calibre_dir() && empty($req_configs[CALIBRE_DIR]))
		array_push($errors, 1);
	# Calibre dir changed, check it for existence
	elseif (array_key_exists(CALIBRE_DIR, $req_configs)) {		
		$req_calibre_dir = $req_configs[CALIBRE_DIR];
		if ($req_calibre_dir != $globalSettings[CALIBRE_DIR]) {
			if (!BicBucStriim::checkForCalibre($req_calibre_dir))
				array_push($errors, 1);
		}
	} 
	## More consistency checks - download protection
	# Switch off DL protection, if there is a problem with the configuration
	if ($req_configs[GLOB_DL_CHOICE] != "0") {
		if($req_configs[GLOB_DL_CHOICE] == "1" && empty($req_configs[ADMIN_PW])) {
			array_push($errors, 3);
		} elseif ($req_configs[GLOB_DL_CHOICE] == "2" && empty($req_configs[GLOB_DL_PASSWORD])) {
			array_push($errors, 2);
		}
	}			

	# Don't save just return the error status
	if (count($errors) > 0) {
		$app->getLog()->error('admin_change: ended with error '.var_export($errors, true));	
		$app->render('admin_status.html',array(
		'page' => mkPage($globalSettings['langa']['admin']), 
		'status_ok' => false,
		'errors' => $errors));	
	} else {
		## Apply changes 
		foreach ($req_configs as $key => $value) {
			if (!isset($globalSettings[$key]) || $value != $globalSettings[$key]) {
				$c1 = new Config();
				$c1->name = $key;
				$c1->val = $value;
				array_push($nconfigs,$c1);
				$globalSettings[$key] = $value;
				$app->getLog()->debug('admin_change: '.$key.' changed: '.$value);	
			}
		}
		# Save changes
		if (count($nconfigs) > 0) {
			$bbs->saveConfigs($nconfigs);
			$app->getLog()->debug('admin_change: changes saved');	
		}
		$app->getLog()->debug('admin_change: ended');	
		$app->render('admin_status.html',array(
			'page' => mkPage($globalSettings['langa']['admin']), 
			'status_ok' => true));	
	}
}

# Checks access to the admin page -> /admin/access/check
function admin_checkaccess() {
	global $app, $globalSettings, $bbs;

	$app->deleteCookie(ADMIN_COOKIE);
	$password = $app->request()->post('admin_pwin');
	$app->getLog()->debug('admin_checkaccess input: '.$password);

	$response = $app->response();
	$response['Content-Type'] = 'application/json';
	$response['X-Powered-By'] = 'Slim';
	$response->status(200);
	if ($password == $globalSettings[ADMIN_PW]) {
		$app->getLog()->debug('admin_checkaccess succeded');
		$app->setCookie(ADMIN_COOKIE,$password);
		$answer = array('access' => true);
	} else {		
		$app->getLog()->debug('admin_checkaccess failed');
		$answer = array('access' => false, 'message' => $globalSettings['langa']['invalid_password']);
	}
	$response->body(json_encode($answer));
}

# Check if the admin page is protected by a password
# -> /admin/access/
function admin_is_protected() {
	global $app, $globalSettings;

	if (!empty($globalSettings[ADMIN_PW])) {
		$app->getLog()->debug('admin_is_protected: yes');
		$app->response()->status(200);
		$app->response()->body('1');
	} else {		
		$app->getLog()->debug('admin_is_protected: no');
		$app->response()->status(200);
		$app->response()->body('0');
	}	
}


# A list of all titles -> /titles/
function titles() {
	global $app, $globalSettings, $bbs;

	$grouped_books = $bbs->allTitles();
	$app->render('titles.html',array(
		'page' => mkPage($globalSettings['langa']['titles'],2), 
		'books' => $grouped_books));
}

# A list of titles at $index -> /titlesList/:index
function titlesSlice($index=0) {
	global $app, $globalSettings, $bbs;

	$search = $app->request()->get('search');
	if (isset($search))
		$tl = $bbs->titlesSlice($index,$globalSettings['pagentries'],$search);
	else
		$tl = $bbs->titlesSlice($index,$globalSettings['pagentries']);
	$app->render('titles.html',array(
		'page' => mkPage($globalSettings['langa']['titles'],2), 
		'url' => 'titleslist',
		'books' => $tl['entries'],
		'curpage' => $tl['page'],
		'pages' => $tl['pages'],
		'search' => $search));
}

# Show a single title > /titles/:id. The ID ist the Calibre ID
function title($id) {
	global $app, $calibre_dir, $globalSettings, $bbs;
	
	$details = $bbs->titleDetails($id);	
	if (is_null($details)) {
		$app->getLog()->debug("title: book not found: ".$id);
		$app->notFound();
		return;
	}	

	$app->render('title_detail.html',
		array('page' => mkPage($globalSettings['langa']['book_details']), 
			'calibre_dir' => $calibre_dir,
			'book' => $details['book'], 
			'authors' => $details['authors'], 
			'tags' => $details['tags'], 
			'formats'=>$details['formats'], 
			'comment' => $details['comment'],
			'protect_dl' => is_protected($id))
	);
}

# Show the password dialog
# Route: /titles/:id/showaccess/
function showaccess($id) {
	global $app, $globalSettings;

	$app->getLog()->debug('showaccess called for '.$id);			
	$app->render('password_dialog.html',
		array('page' => mkPage($globalSettings['langa']['check_access'],0,true), 
					'bookid' => $id));
}

# Check the access rights for a book and set a cookie if successful.
# Sends 404 if unsuccessful.
# Route: /titles/:id/checkaccess/
function checkaccess($id) {
	global $app, $calibre_dir, $globalSettings, $bbs;

	$rot = $app->request()->getRootUri();
	$book = $bbs->title($id);
	if (is_null($book)) {
		$app->getLog()->debug("checkaccess: book not found: ".$id);
		$app->response()->status(404);
		return;
	}	
	$app->deleteCookie(GLOBAL_DL_COOKIE);
	$password = $app->request()->post('password');
	$app->getLog()->debug('checkaccess input: '.$password);

	if ($globalSettings[GLOB_DL_CHOICE] == "1") 
		$cpw = $globalSettings[ADMIN_PW];
	elseif ($globalSettings[GLOB_DL_CHOICE] == "2") 
		$cpw = $globalSettings[GLOB_DL_PASSWORD];

	if ($password == $cpw) {
		$app->getLog()->debug('checkaccess succeded');
		$app->setCookie(GLOBAL_DL_COOKIE,$cpw);
		$app->response()->status(200);
	} else {		
		$app->getLog()->debug('checkaccess failed');
		$app->flash('error', $globalSettings['langa']['invalid_password']);
		$app->response()->status(404);
	}
}

# Return the cover for the book with ID. Calibre generates only JPEGs, so we always return a JPEG.
# If there is no cover, return 404.
# Route: /titles/:id/cover
function cover($id) {
	global $app, $calibre_dir, $bbs;

	$has_cover = false;
	$rot = $app->request()->getRootUri();
	$book = $bbs->title($id);
	if (is_null($book)) {
		$app->getLog()->debug("cover: book not found: "+$id);
		$app->response()->status(404);
		return;
	}
	
	if ($book->has_cover) {		
		$cover = $bbs->titleCover($id);
		$has_cover = true;
	}
	if ($has_cover) {
		$app->response()->status(200);
		$app->response()->header('Content-type','image/jpeg;base64');
		$app->response()->header('Content-Length',filesize($cover));
		readfile($cover);		
	} else {
		$app->response()->status(404);
	}
}

# Return the cover for the book with ID. Calibre generates only JPEGs, so we always return a JPEG.
# If there is no cover, return 404.
# Route: /titles/:id/thumbnail
function thumbnail($id) {
	global $app, $calibre_dir, $bbs;

	$has_cover = false;
	$rot = $app->request()->getRootUri();
	$book = $bbs->title($id);
	if (is_null($book)) {
		$app->getLog()->error("thumbnail: book not found: "+$id);
		$app->response()->status(404);
		return;
	}
	
	if ($book->has_cover) {		
		$thumb = $bbs->titleThumbnail($id);
		$has_cover = true;
	}
	if ($has_cover) {
		$app->response()->status(200);
		$app->response()->header('Content-type','image/jpeg;base64');
		$app->response()->header('Content-Length',filesize($thumb));
		readfile($thumb);		
	} else {
		$app->response()->status(404);
	}
}


# Return the selected file for the book with ID. 
# Route: /titles/:id/file/:file
function book($id, $file) {
	global $app, $bbs;

	$book = $bbs->title($id);
	if (is_null($book)) {
		$app->getLog()->debug("no book file");
		$app->notFound();
	}	
	if (is_protected($id)) {
		$app->getLog()->warn("book: attempt to download a protected book, "+$id);		
		$app->response()->status(404);	
	} else {
		$app->getLog()->debug("book: file ".$file);
		$bookpath = $bbs->titleFile($id, $file);
		$app->getLog()->debug("book: path ".$bookpath);

		/** readfile has problems with large files (e.g. PDF) caused by php memory limit
		 * to avoid this the function readfile_chunked() is used. app->response() is not
		 * working with this solution.
		**/
		//TODO: Use new streaming functions in SLIM 1.7.0 when released
		header("Content-length: ".filesize($bookpath));
		header("Content-type: ".Utilities::titleMimeType($bookpath));
		readfile_chunked($bookpath);
	}
}

# List of all authors -> /authors
function authors() {
	global $app, $globalSettings, $bbs;

	$grouped_authors = $bbs->allAuthors();		
	$app->render('authors.html',array(
		'page' => mkPage($globalSettings['langa']['authors'],3), 
		'authors' => $grouped_authors));
}

# A list of authors at $index -> /authorslist/:index
function authorsSlice($index=0) {
	global $app, $globalSettings, $bbs;

	$search = $app->request()->get('search');
	if (isset($search))
		$tl = $bbs->authorsSlice($index,$globalSettings['pagentries'],$search);	
	else
		$tl = $bbs->authorsSlice($index,$globalSettings['pagentries']);
	$app->render('authors.html',array(
		'page' => mkPage($globalSettings['langa']['authors'],3), 
		'url' => 'authorslist',
		'authors' => $tl['entries'],
		'curpage' => $tl['page'],
		'pages' => $tl['pages'],
		'search' => $search));
}

# Details for a single author -> /authors/:id
function author($id) {
	global $app, $globalSettings, $bbs;

	$details = $bbs->authorDetails($id);
	if (is_null($details)) {
		$app->getLog()->debug("no author");
		$app->notFound();		
	}
	$app->render('author_detail.html',array(
		'page' => mkPage($globalSettings['langa']['author_details']), 
		'author' => $details['author'], 
		'books' => $details['books']));
}

#List of all tags -> /tags
function tags() {
	global $app, $globalSettings, $bbs;

	$grouped_tags = $bbs->allTags();
	$app->render('tags.html',array(
		'page' => mkPage($globalSettings['langa']['tags'],4),
		'tags' => $grouped_tags));
}

# A list of tags at $index -> /tagslist/:index
function tagsSlice($index=0) {
	global $app, $globalSettings, $bbs;

	$search = $app->request()->get('search');
	if (isset($search))
		$tl = $bbs->tagsSlice($index,$globalSettings['pagentries'],$search);
	else
		$tl = $bbs->tagsSlice($index,$globalSettings['pagentries']);
	$app->render('tags.html',array(
		'page' => mkPage($globalSettings['langa']['tags'],4), 
		'url' => 'tagslist',
		'tags' => $tl['entries'],
		'curpage' => $tl['page'],
		'pages' => $tl['pages'],
		'search' => $search));
}

#Details of a single tag -> /tags/:id
function tag($id) {
	global $app, $globalSettings, $bbs;

	$details = $bbs->tagDetails($id);
	if (is_null($details)) {
		$app->getLog()->debug("no tag");
		$app->notFound();		
	}
	$app->render('tag_detail.html',array('page' => mkPage($globalSettings['langa']['tag_details']), 
		'tag' => $details['tag'], 
		'books' => $details['books']));
}

#####
##### OPDS Catalog functions
#####

function opdsCheckConfig() {
	global $we_have_config, $app;

	$result = check_config();
	if ($result != 0) {
		$app->getLog()->error('opdsCheckConfig: Configuration invalid, check config error '.$result);	
		$app->response()->status(500);
		$app->response()->header('Content-type','text/html');
		$app->response()->body('<p>BucBucStriim: Invalid Configuration.</p>');
	}
}

/**
 * Generate and send the OPDS root navigation catalog
 */
function opdsRoot() {
	global $app, $appversion, $bbs;

	$app->getLog()->debug('opdsRoot started');			
	#$gen = new OpdsGenerator($app->request()->getRootUri(), $appversion, 
	$gen = new OpdsGenerator('http://borg.fritz.box:8080/bbs', $appversion, 
		$bbs->calibre_dir,
		date(DATE_ATOM,$bbs->calibre_last_modified));
	$cat = $gen->rootCatalog(NULL);	
	$app->response()->status(200);
	$app->response()->header('Content-Type',OpdsGenerator::OPDS_MIME_NAV);
	$app->response()->header('Content-Length',strlen($cat));
	$app->response()->body($cat);
	$app->getLog()->debug('opdsRoot ended');			
}

/**
 * Generate and send the OPDS 'newest' catalog. This catalog is an
 * acquisition catalog with a subset of the title details.
 *
 * Note: OPDS acquisition feeds need an acquisition link for every item,
 * so books without formats are removed from the output.
 */
function opdsNewest() {
	global $app, $appversion, $bbs;

	$app->getLog()->debug('opdsNewest started');			
	$just_books = $bbs->last30Books();
	$app->getLog()->debug('opdsNewest: 30 books found');			
	$books = array();
	foreach ($just_books as $book) {
		$record = $bbs->titleDetailsOpds($book);
		if (!empty($record['formats']))
			array_push($books,$record);
	}
	$app->getLog()->debug('opdsNewest: details found');			
	$gen = new OpdsGenerator($app->request()->getRootUri(), $appversion, 
		$bbs->calibre_dir,
		date(DATE_ATOM,$bbs->calibre_last_modified));
	$app->response()->status(200);
	$app->response()->header('Content-type',OpdsGenerator::OPDS_MIME_ACQ);
	$gen->newestCatalog('php://output', $books, false);
	$app->getLog()->debug('opdsNewest ended');			
}

/**
 * Return a page of the titles. 
 * 
 * Note: OPDS acquisition feeds need an acquisition link for every item,
 * so books without formats are removed from the output.
 * 
 * @param  integer $index=0 page index
 */
function opdsByTitle($index=0) {
	global $app, $appversion, $bbs, $globalSettings;

	$app->getLog()->debug('opdsByTitle started, showing page '.$index);			
	$search = $app->request()->get('search');
	if (isset($search))
		$tl = $bbs->titlesSlice($index,$globalSettings['pagentries'],$search);
	else
		$tl = $bbs->titlesSlice($index,$globalSettings['pagentries']);
	$app->getLog()->debug('opdsByTitle: books found');			
	$books = array();
	foreach ($tl['entries'] as $book) {
		$record = $bbs->titleDetailsOpds($book);
		if (!empty($record['formats']))
			array_push($books,$record);
	}
	$app->getLog()->debug('opdsByTitle: details found');
	if ($tl['page'] < $tl['pages']-1)
		$nextPage = $tl['page']+1;
	else
		$nextPage = NULL;
	$gen = new OpdsGenerator($app->request()->getRootUri(), $appversion, 
		$bbs->calibre_dir,
		date(DATE_ATOM,$bbs->calibre_last_modified));
	$app->response()->status(200);
	$app->response()->header('Content-type',OpdsGenerator::OPDS_MIME_ACQ);
	# protection = is_protected(NULL)
	$gen->titlesCatalog('php://output', $books, false, $tl['page'], $nextPage, $tl['pages']-1);
	$app->getLog()->debug('opdsByTitle ended');			
}

/**
 * Return a page with author names initials
 */
function opdsByAuthorInitial() {
	global $app, $appversion, $bbs, $globalSettings;

	$app->getLog()->debug('opdsByAuthorInitial started');			
	$initials = $bbs->authorsInitials();
	$app->getLog()->debug('opdsByAuthorInitial: initials found');			
	$gen = new OpdsGenerator($app->request()->getRootUri(), $appversion, 
		$bbs->calibre_dir,
		date(DATE_ATOM,$bbs->calibre_last_modified));
	$app->response()->status(200);
	$app->response()->header('Content-type',OpdsGenerator::OPDS_MIME_NAV);
	$gen->authorsRootCatalog('php://output', $initials);
	$app->getLog()->debug('opdsByAuthorInitial ended');			
}

/**
 * Return a page with author names for a initial
 */
function opdsByAuthorNamesForInitial($initial) {
	global $app, $appversion, $bbs;

	$app->getLog()->debug('opdsByAuthorNamesForInitial started, showing initial '.$initial);			
	$authors = $bbs->authorsNamesForInitial($initial);
	$app->getLog()->debug('opdsByAuthorNamesForInitial: initials found');			
	$gen = new OpdsGenerator($app->request()->getRootUri(), $appversion, 
		$bbs->calibre_dir,
		date(DATE_ATOM,$bbs->calibre_last_modified));
	$app->response()->status(200);
	$app->response()->header('Content-type',OpdsGenerator::OPDS_MIME_NAV);
	$gen->authorsNamesForInitialCatalog('php://output', $authors, $initial);
	$app->getLog()->debug('opdsByAuthorNamesForInitial ended');			
}

function opdsByAuthor($initial,$id) {
	global $app, $appversion, $bbs;

	$app->getLog()->debug('opdsByAuthor started, showing initial '.$initial.', id '.$id);			
	$adetails = $bbs->authorDetails($id);
	$books = array();
	foreach ($adetails['books'] as $book) {
		$record = $bbs->titleDetailsOpds($book);
		if (!empty($record['formats']))
			array_push($books,$record);
	}
	
	$app->getLog()->debug('opdsByAuthor: details found');			
	$gen = new OpdsGenerator($app->request()->getRootUri(), $appversion, 
		$bbs->calibre_dir,
		date(DATE_ATOM,$bbs->calibre_last_modified));
	$app->response()->status(200);
	$app->response()->header('Content-type',OpdsGenerator::OPDS_MIME_NAV);
	$gen->booksForAuthorCatalog('php://output', $books, $initial, 
		$adetails['author'],is_protected(NULL));
	$app->getLog()->debug('opdsByAuthor ended');				
}

/**
 * Return a page of the titles or 404 if not found
 * @param  integer $index=0 page index
 */
function opdsByTag($index=0) {
	global $app, $appversion, $bbs, $globalSettings;

	$app->getLog()->debug('opdsByTag started, showing page '.$index);			
	$search = $app->request()->get('search');
	if (isset($search))
		$tl = $bbs->titlesSlice($index,$globalSettings['pagentries'],$search);
	else
		$tl = $bbs->titlesSlice($index,$globalSettings['pagentries']);
	$app->getLog()->debug('opdsByTag: books found');			
	$books = array();
	foreach ($tl['entries'] as $book)
		array_push($books,$bbs->titleDetailsOpds($book));
	$app->getLog()->debug('opdsTitles: details found');
	if ($tl['page'] < $tl['pages']-1)
		$nextPage = $tl['page']+1;
	else
		$nextPage = NULL;
	$gen = new OpdsGenerator($app->request()->getRootUri(), $appversion, 
		$bbs->calibre_dir,
		date(DATE_ATOM,$bbs->calibre_last_modified));
	$app->response()->status(200);
	$app->response()->header('Content-type',OpdsGenerator::OPDS_MIME_ACQ);
	$gen->titlesCatalog('php://output', $books, is_protected(NULL), $tl['page'], $nextPage, $tl['pages']-1);
	$app->getLog()->debug('opdsByTag ended');			
}

#####
##### Utility and helper functions, private
#####

/**
 * Check whether the book download must be protected. 
 * The ID parameter is for future use (selective download protection)
 * 
 * @param  int  		$id book id, currently not used
 * @return boolean  true - the user must enter a password, else no authentication necessary
 */
function is_protected($id=NULL) {
	global $app, $globalSettings;

	# Get the cookie
	# TBD Check the cookie content
	$glob_dl_cookie = $app->getCookie(GLOBAL_DL_COOKIE);
	$app->getLog()->debug('is_protected: Cookie glob_dl_access value: '.$glob_dl_cookie);			
	if ($globalSettings[GLOB_DL_CHOICE] != "0" && is_null($glob_dl_cookie)) {
		$app->getLog()->debug('is_protected: book is protected, no cookie, ask for password');		
		return true;
	}	else {
		$app->getLog()->debug('is_protected: book is not protected');		
		return false;
	}		
}


# Utility function to fill the page array
function mkPage($subtitle='', $menu=0, $dialog=false) {
	global $app, $globalSettings;

	if ($subtitle == '') 
		$title = $globalSettings['appname'];
	else
		$title = $globalSettings['appname'].$globalSettings['sep'].$subtitle;
	$rot = $app->request()->getRootUri();
	$page = array('title' => $title, 
		'rot' => $rot,
		'h1' => $subtitle,
		'version' => $globalSettings['version'],
		'glob' => $globalSettings,
		'menu' => $menu,
		'dialog' => $dialog);
	return $page;
}

/**
 * getUserLangs()
 * Returns the user language, priority:
 * 1. Language in $_GET['lang']
 * 2. Language in $_SESSION['lang']
 * 3. HTTP_ACCEPT_LANGUAGE
 * 4. Fallback language
 *
 * @return the user language, like 'de' or 'en'
 */
function getUserLang($allowedLangs, $fallbackLang) {
  // reset user_lang array
  $userLangs = array();
  // 2nd highest priority: GET parameter 'lang'
  if(isset($_GET['lang']) && is_string($_GET['lang'])) {
      $userLangs[] =  $_GET['lang'];
  }
	// 3rd highest priority: SESSION parameter 'lang'
  if(isset($_SESSION['lang']) && is_string($_SESSION['lang'])) {
      $userLangs[] = $_SESSION['lang'];
  }
  // 4th highest priority: HTTP_ACCEPT_LANGUAGE
  if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    foreach (explode(',',$_SERVER['HTTP_ACCEPT_LANGUAGE']) as $part) {
      $userLangs[] = strtolower(substr($part,0,2));
    }
  }
  // Lowest priority: fallback
  $userLangs[] = $fallbackLang;    
  foreach($allowedLangs as $al) {
  	if ($userLangs[0] == $al)
  		return $al;
  }
  return $fallbackLang;
}

#Utility function to server files
function readfile_chunked($filename) {
	global $app;
	$app->getLog()->debug('readfile_chunked '.$filename);
	$buffer = '';
	$handle = fopen($filename, 'rb');
	if ($handle === false) {
		return false;
	}
	while (!feof($handle)) {
		$buffer = fread($handle, 1024*1024);
		echo $buffer;
		ob_flush();
		flush();
	}
	$status = fclose($handle);
	return $status;
	
}

?>
