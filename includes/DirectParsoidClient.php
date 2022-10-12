<?php
/**
 * Helper functions for using the REST interface to Parsoid.
 *
 * @file
 * @ingroup Extensions
 * @copyright 2022 VisualEditor Team and others; see AUTHORS.txt
 * @license MIT
 */

namespace MediaWiki\Extension\VisualEditor;

use Exception;
use IBufferingStatsdDataFactory;
use Language;
use LocalizedException;
use MediaWiki\Edit\ParsoidOutputStash;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Parser\Parsoid\HTMLTransformFactory;
use MediaWiki\Parser\Parsoid\ParsoidOutputAccess;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\Handler\HtmlInputTransformHelper;
use MediaWiki\Rest\Handler\HtmlOutputRendererHelper;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use RawMessage;
use User;
use WikitextContent;

class DirectParsoidClient implements ParsoidClient {
	/**
	 * Requested Parsoid HTML version.
	 * Keep this in sync with the Accept: header in
	 * ve.init.mw.ArticleTargetLoader.js
	 */
	public const PARSOID_VERSION = '2.4.0';
	private const FLAVOR_DEFAULT = 'view';

	/** @var ParsoidOutputStash */
	private $parsoidOutputStash;

	/** @var IBufferingStatsdDataFactory */
	private $stats;

	/** @var ParsoidOutputAccess */
	private $parsoidOutputAccess;

	/** @var Authority */
	private $performer;

	/** @var HTMLTransformFactory */
	private $htmlTransformFactory;

	/**
	 * @param ParsoidOutputStash $parsoidOutputStash
	 * @param IBufferingStatsdDataFactory $statsDataFactory
	 * @param ParsoidOutputAccess $parsoidOutputAccess
	 * @param HTMLTransformFactory $htmlTransformFactory
	 * @param Authority $performer
	 */
	public function __construct(
		ParsoidOutputStash $parsoidOutputStash,
		IBufferingStatsdDataFactory $statsDataFactory,
		ParsoidOutputAccess $parsoidOutputAccess,
		HTMLTransformFactory $htmlTransformFactory,
		Authority $performer
	) {
		$this->parsoidOutputStash = $parsoidOutputStash;
		$this->stats = $statsDataFactory;
		$this->parsoidOutputAccess = $parsoidOutputAccess;
		$this->htmlTransformFactory = $htmlTransformFactory;
		$this->performer = $performer;
	}

	/**
	 * @param PageIdentity $page
	 * @param RevisionRecord|null $revision
	 * @param Language|null $pageLanguage
	 * @param bool $stash
	 * @param string $flavor
	 *
	 * @return HtmlOutputRendererHelper
	 */
	private function getHtmlOutputRendererHelper(
		PageIdentity $page,
		?RevisionRecord $revision = null,
		Language $pageLanguage = null,
		bool $stash = false,
		string $flavor = self::FLAVOR_DEFAULT
	): HtmlOutputRendererHelper {
		$helper = new HtmlOutputRendererHelper(
			$this->parsoidOutputStash,
			$this->stats,
			$this->parsoidOutputAccess
		);

		// Fake REST params
		$params = [
			'stash' => $stash,
			'flavor' => $flavor,
		];

		$user = User::newFromIdentity( $this->performer->getUser() );
		$helper->init( $page, $params, $user, $revision, $pageLanguage );
		return $helper;
	}

	/**
	 * @param PageIdentity $page
	 * @param string $html
	 * @param int|null $oldid
	 * @param string|null $etag
	 * @param Language|null $pageLanguage
	 *
	 * @return HtmlInputTransformHelper
	 */
	private function getHtmlInputTransformHelper(
		PageIdentity $page,
		string $html,
		int $oldid = null,
		string $etag = null,
		Language $pageLanguage = null
	): HtmlInputTransformHelper {
		$helper = new HtmlInputTransformHelper(
			$this->stats,
			$this->htmlTransformFactory,
			$this->parsoidOutputStash,
			$this->parsoidOutputAccess
		);

		// Fake REST body
		$body = [
			'html' => [
				'body' => $html,
			],
			'original' => [
				'revid' => $oldid,
				'etag' => $etag,
			]
		];

		$helper->init( $page, $body, [], null, $pageLanguage );

		return $helper;
	}

	/**
	 * Request page HTML from Parsoid.
	 *
	 * @param RevisionRecord $revision Page revision
	 * @param ?Language $targetLanguage Page language (default: `null`)
	 *
	 * @return array An array mimicking a RESTbase server's response,
	 *   with keys: 'error', 'headers' and 'body'
	 */
	public function getPageHtml( RevisionRecord $revision, ?Language $targetLanguage = null ): array {
		// In the VE client, we always want to stash.
		$page = $revision->getPage();
		$helper = $this->getHtmlOutputRendererHelper( $page, $revision, $targetLanguage, true );

		try {
			$parserOutput = $helper->getHtml();

			return $this->fakeRESTbaseHTMLResponse( $parserOutput->getRawText(), $helper );
		} catch ( HttpException $ex ) {
			return $this->fakeRESTbaseError( $ex );
		}
	}

	/**
	 * @param PageIdentity $page
	 * @param string $wikitext
	 *
	 * @return RevisionRecord
	 */
	private function makeFakeRevision(
		PageIdentity $page,
		string $wikitext
	): RevisionRecord {
		$rev = new MutableRevisionRecord( $page );
		$rev->setId( 0 );
		$rev->setPageId( $page->getId() );

		$rev->setContent( SlotRecord::MAIN, new WikitextContent( $wikitext ) );

		return $rev;
	}

	/**
	 * Transform wikitext to HTML with Parsoid. Wrapper for ::postData().
	 *
	 * @param PageIdentity $page The page the content belongs to use as the parsing context
	 * @param Language $targetLanguage Page language
	 * @param string $wikitext The wikitext fragment to parse
	 * @param bool $bodyOnly Whether to provide only the contents of the `<body>` tag
	 * @param int|null $oldid What oldid revision, if any, to base the request from (default: `null`)
	 * @param bool $stash Whether to stash the result in the server-side cache (default: `false`)
	 *
	 * @return array An array mimicking a RESTbase server's response,
	 *   with keys 'code', 'reason', 'headers' and 'body'
	 */
	public function transformWikitext(
		PageIdentity $page,
		Language $targetLanguage,
		string $wikitext,
		bool $bodyOnly,
		?int $oldid,
		bool $stash
	): array {
		$revision = $this->makeFakeRevision( $page, $wikitext );
		$helper = $this->getHtmlOutputRendererHelper( $page, $revision, $targetLanguage, $stash );

		if ( $bodyOnly ) {
			$helper->setFlavor( 'fragment' );
		}

		try {
			$parserOutput = $helper->getHtml();
			$html = $parserOutput->getRawText();

			return $this->fakeRESTbaseHTMLResponse( $html, $helper );
		} catch ( HttpException $ex ) {
			return $this->fakeRESTbaseError( $ex );
		}
	}

	/**
	 * Transform HTML to wikitext with Parsoid
	 *
	 * @param PageIdentity $page The page the content belongs to
	 * @param Language $targetLanguage The desired output language
	 * @param string $html The HTML of the page to be transformed
	 * @param ?int $oldid What oldid revision, if any, to base the request from (default: `null`)
	 * @param ?string $etag The ETag to set in the HTTP request header
	 *
	 * @return array The response, 'code', 'reason', 'headers' and 'body'
	 */
	public function transformHTML(
		PageIdentity $page, Language $targetLanguage, string $html, ?int $oldid, ?string $etag
	): array {
		$helper = $this->getHtmlInputTransformHelper( $page, $html, $oldid, $etag, $targetLanguage );

		try {
			$content = $helper->getContent();
			$format = $content->getDefaultFormat();

			return [
				'code' => 200,
				'headers' => [
					'Content-Type' => $format,
				],
				'body' => $content->serialize( $format ),
			];
		} catch ( HttpException $ex ) {
			return $this->fakeRESTbaseError( $ex );
		}
	}

	/**
	 * @param mixed $data
	 * @param HtmlOutputRendererHelper $helper
	 *
	 * @return array
	 */
	private function fakeRESTbaseHTMLResponse( $data, HtmlOutputRendererHelper $helper ): array {
		return [
			'code' => 200,
			'headers' => [
				'content-language' => $helper->getHtmlOutputContentLanguage(),
				'etag' => $helper->getETag()
			],
			'body' => $data,
		];
	}

	/**
	 * @param Exception $ex
	 *
	 * @return array
	 */
	private function fakeRESTbaseError( Exception $ex ): array {
		if ( $ex instanceof LocalizedHttpException ) {
			$msg = $ex->getMessageValue();
		} elseif ( $ex instanceof LocalizedException ) {
			$msg = $ex->getMessageObject();
		} else {
			$msg = new RawMessage( $ex->getMessage() );
		}

		return [
			'error' => [
				'message' => $msg->getKey() ?? '',
				'params' => $msg->getParams() ?? []
			],
			'headers' => [],
			'body' => $ex->getMessage(),
		];
	}

}
