<?php
/**
 * Maps a GitHub repository name to the Moodle plugin it contains and the
 * directory that plugin must be installed into.
 *
 * @package WCGP
 */

namespace WCGP\Moodle;

defined( 'ABSPATH' ) || exit;

/**
 * Moodle plugins follow the "frankenstyle" convention `{type}_{name}` and their
 * repositories are conventionally named `moodle-{type}_{name}` (e.g.
 * `moodle-mod_videotime`). Each plugin type installs into a fixed directory
 * under the Moodle root. This class derives that mapping so the bundle's
 * INSTALL.md can tell a manual installer exactly where each component goes.
 */
class ComponentMap {

	/**
	 * Moodle plugin type => install directory (relative to the Moodle root),
	 * without the trailing plugin-name segment. The five types used by the
	 * Kickstart and Video Time products are covered, plus the other common ones.
	 *
	 * @var array<string,string>
	 */
	const TYPE_DIRS = array(
		'mod'                => '/mod',
		'format'             => '/course/format',
		'local'              => '/local',
		'filter'             => '/filter',
		'tiny'               => '/lib/editor/tiny/plugins',
		'atto'               => '/lib/editor/atto/plugins',
		'editor'             => '/lib/editor',
		'block'              => '/blocks',
		'theme'              => '/theme',
		'auth'               => '/auth',
		'enrol'             => '/enrol',
		'tool'               => '/admin/tool',
		'report'             => '/report',
		'repository'         => '/repository',
		'portfolio'          => '/portfolio',
		'qtype'              => '/question/type',
		'qformat'            => '/question/format',
		'qbehaviour'         => '/question/behaviour',
		'qbank'              => '/question/bank',
		'quizaccess'         => '/mod/quiz/accessrule',
		'assignsubmission'   => '/mod/assign/submission',
		'assignfeedback'     => '/mod/assign/feedback',
		'datafield'          => '/mod/data/field',
		'datapreset'         => '/mod/data/preset',
		'booktool'           => '/mod/book/tool',
		'gradereport'        => '/grade/report',
		'gradeexport'        => '/grade/export',
		'gradeimport'        => '/grade/import',
		'gradingform'        => '/grade/grading/form',
		'availability'       => '/availability/condition',
		'customfield'        => '/customfield/field',
		'profilefield'       => '/user/profile/field',
		'messageoutput'      => '/message/output',
		'webservice'         => '/webservice',
		'search'             => '/search/engine',
		'media'              => '/media/player',
		'plagiarism'         => '/plagiarism',
		'cachestore'         => '/cache/stores',
		'cachelock'          => '/cache/locks',
		'contenttype'        => '/contentbank/contenttype',
		'h5plib'             => '/h5p/h5plib',
		'fileconverter'      => '/files/converter',
		'dataformat'         => '/dataformat',
		'antivirus'          => '/lib/antivirus',
		'logstore'           => '/admin/tool/log/store',
		'coursereport'       => '/course/report',
		'webservice_rest'    => '/webservice/rest',
	);

	/**
	 * Resolve a repository name to its Moodle component and install directory.
	 *
	 * @param string $repo     Repo reference ("owner/moodle-mod_videotime", a URL,
	 *                          or a bare "moodle-mod_videotime").
	 * @param string $override Optional explicit install path; when non-empty it
	 *                         wins over the derived directory.
	 * @return array{
	 *     component:string, type:string, name:string,
	 *     target_dir:string, known:bool
	 * }
	 */
	public static function resolve( $repo, $override = '' ) {
		$component = self::component_from_repo( $repo );

		$type = '';
		$name = $component;
		$pos  = strpos( $component, '_' );
		if ( false !== $pos ) {
			$type = substr( $component, 0, $pos );
			$name = substr( $component, $pos + 1 );
		}

		$known = '' !== $type && isset( self::TYPE_DIRS[ $type ] );

		if ( '' !== trim( (string) $override ) ) {
			$target_dir = '/' . trim( trim( (string) $override ), '/' );
		} elseif ( $known ) {
			$target_dir = self::TYPE_DIRS[ $type ] . '/' . $name;
		} else {
			// Unknown type: best-effort guess of /{type}/{name}; INSTALL.md flags
			// it as unverified and the per-repo override can correct it.
			$target_dir = $type ? '/' . $type . '/' . $name : '/' . $name;
		}

		return array(
			'component'  => $component,
			'type'       => $type,
			'name'       => $name,
			'target_dir' => $target_dir,
			'known'      => $known,
		);
	}

	/**
	 * Extract the frankenstyle component ("type_name") from a repo reference:
	 * take the last path segment and strip a leading "moodle-".
	 *
	 * @param string $repo Repo reference.
	 * @return string
	 */
	public static function component_from_repo( $repo ) {
		$repo = trim( (string) $repo );
		$repo = preg_replace( '#^https?://github\.com/#i', '', $repo );
		$repo = preg_replace( '#\.git$#', '', $repo );
		$repo = trim( $repo, '/' );

		$slash = strrpos( $repo, '/' );
		$slug  = false === $slash ? $repo : substr( $repo, $slash + 1 );

		if ( 0 === strpos( $slug, 'moodle-' ) ) {
			$slug = substr( $slug, strlen( 'moodle-' ) );
		}
		return $slug;
	}
}
