<?php

/**
 * @file
 * Contains \Drupal\Component\Utility\SafeMarkup.
 */

namespace Drupal\Component\Utility;

/**
 * Manages known safe strings for rendering at the theme layer.
 *
 * The Twig theme engine autoescapes string variables in the template, so it
 * is possible for a string of markup to become double-escaped. SafeMarkup
 * provides a store for known safe strings and methods to manage them
 * throughout the page request.
 *
 * Strings sanitized by self::checkPlain() and self::escape() are automatically
 * marked safe, as are markup strings created from @link theme_render render
 * arrays @endlink via drupal_render().
 *
 * This class should be limited to internal use only. Module developers should
 * instead use the appropriate
 * @link sanitization sanitization functions @endlink or the
 * @link theme_render theme and render systems @endlink so that the output can
 * can be themed, escaped, and altered properly.
 *
 * @see TwigExtension::escapeFilter()
 * @see twig_render_template()
 * @see sanitization
 * @see theme_render
 */
class SafeMarkup {
  use PlaceholderTrait;

  /**
   * The list of safe strings.
   *
   * Strings in this list are marked as secure for the entire page render, not
   * just the code or element that set it. Therefore, only valid HTML should be
   * marked as safe (never partial markup). For example, you should never mark
   * string such as '<' or '<script>' safe.
   *
   * @var array
   */
  protected static $safeStrings = array();

  /**
   * Checks if a string is safe to output.
   *
   * @param string|\Drupal\Component\Utility\SafeStringInterface $string
   *   The content to be checked.
   * @param string $strategy
   *   The escaping strategy. Defaults to 'html'. Two escaping strategies are
   *   supported by default:
   *   - 'html': (default) The string is safe for use in HTML code.
   *   - 'all': The string is safe for all use cases.
   *   See the
   *   @link http://twig.sensiolabs.org/doc/filters/escape.html Twig escape documentation @endlink
   *   for more information on escaping strategies in Twig.
   *
   * @return bool
   *   TRUE if the string has been marked secure, FALSE otherwise.
   */
  public static function isSafe($string, $strategy = 'html') {
    // Do the instanceof checks first to save unnecessarily casting the object
    // to a string.
    return $string instanceOf SafeStringInterface || isset(static::$safeStrings[(string) $string][$strategy]) ||
      isset(static::$safeStrings[(string) $string]['all']);
  }

  /**
   * Adds previously retrieved known safe strings to the safe string list.
   *
   * This method is for internal use. Do not use it to prevent escaping of
   * markup; instead, use the appropriate
   * @link sanitization sanitization functions @endlink or the
   * @link theme_render theme and render systems @endlink so that the output
   * can be themed, escaped, and altered properly.
   *
   * This marks strings as secure for the entire page render, not just the code
   * or element that set it. Therefore, only valid HTML should be
   * marked as safe (never partial markup). For example, you should never do:
   * @code
   *   SafeMarkup::setMultiple(['<' => ['html' => TRUE]]);
   * @endcode
   * or:
   * @code
   *   SafeMarkup::setMultiple(['<script>' => ['all' => TRUE]]);
   * @endcode

   * @param array $safe_strings
   *   A list of safe strings as previously retrieved by self::getAll().
   *   Every string in this list will be represented by a multidimensional
   *   array in which the keys are the string and the escaping strategy used for
   *   this string, and in which the value is the boolean TRUE.
   *   See self::isSafe() for the list of supported escaping strategies.
   *
   * @throws \UnexpectedValueException
   *
   * @internal This is called by FormCache, StringTranslation and the Batch API.
   *   It should not be used anywhere else.
   */
  public static function setMultiple(array $safe_strings) {
    foreach ($safe_strings as $string => $strategies) {
      foreach ($strategies as $strategy => $value) {
        $string = (string) $string;
        if ($value === TRUE) {
          static::$safeStrings[$string][$strategy] = TRUE;
        }
        else {
          // Danger - something is very wrong.
          throw new \UnexpectedValueException('Only the value TRUE is accepted for safe strings');
        }
      }
    }
  }

  /**
  * Gets all strings currently marked as safe.
  *
  * This is useful for the batch and form APIs, where it is important to
  * preserve the safe markup state across page requests.
  *
  * @return array
  *   An array of strings currently marked safe.
  */
  public static function getAll() {
    return static::$safeStrings;
  }

  /**
   * Encodes special characters in a plain-text string for display as HTML.
   *
   * Also validates strings as UTF-8. All processed strings are also
   * automatically flagged as safe markup strings for rendering.
   *
   * @param string $text
   *   The text to be checked or processed.
   *
   * @return string
   *   An HTML safe version of $text, or an empty string if $text is not valid
   *   UTF-8.
   *
   * @ingroup sanitization
   *
   * @deprecated Will be removed before Drupal 8.0.0. Rely on Twig's
   *   auto-escaping feature, or use the @link theme_render #plain_text @endlink
   *   key when constructing a render array that contains plain text in order to
   *   use the renderer's auto-escaping feature. If neither of these are
   *   possible, \Drupal\Component\Utility\Html::escape() can be used in places
   *   where explicit escaping is needed.
   *
   * @see drupal_validate_utf8()
   */
  public static function checkPlain($text) {
    $string = Html::escape($text);
    static::$safeStrings[$string]['html'] = TRUE;
    return $string;
  }

  /**
   * Formats a string for HTML display by replacing variable placeholders.
   *
   * This method replaces variable placeholders in a string with the requested
   * values and escapes the values so they can be safely displayed as HTML. It
   * should be used on any unknown text that is intended to be printed to an
   * HTML page (especially text that may have come from untrusted users, since
   * in that case it prevents cross-site scripting and other security problems).
   *
   * This method is not intended for passing arbitrary user input into any
   * HTML attribute value, as only URL attributes such as "src" and "href" are
   * supported (using ":variable"). Never use this method on unsafe HTML
   * attributes such as "on*" and "style" and take care when using this with
   * unsupported attributes such as "title" or "alt" as this can lead to
   * unexpected output.
   *
   * In most cases, you should use t() rather than calling this function
   * directly, since it will translate the text (on non-English-only sites) in
   * addition to formatting it.
   *
   * @param string $string
   *   A string containing placeholders. The string itself is not escaped, any
   *   unsafe content must be in $args and inserted via placeholders.
   * @param array $args
   *   An associative array of replacements to make. Occurrences in $string of
   *   any key in $args are replaced with the corresponding value, after
   *   optional sanitization and formatting. The type of sanitization and
   *   formatting depends on the first character of the key:
   *   - @variable: Escaped to HTML using Html::escape() unless the value is
   *     already HTML-safe. Use this as the default choice for anything
   *     displayed on a page on the site, but not within HTML attributes.
   *   - %variable: Escaped to HTML just like @variable, but also wrapped in
   *     <em> tags, which makes the following HTML code:
   *     @code
   *       <em class="placeholder">text output here.</em>
   *     @endcode
   *     As with @variable, do not use this within HTML attributes.
   *   - :variable: Escaped to HTML using Html::escape() and filtered for
   *     dangerous protocols using UrlHelper::stripDangerousProtocols(). Use
   *     this when passing in a URL, such as when using the "src" or "href"
   *     attributes, ensuring the value is always wrapped in quotes:
   *     - Secure: <a href=":variable">@variable</a>
   *     - Insecure: <a href=:variable>@variable</a>
   *     When ":variable" comes from arbitrary user input, the result is secure,
   *     but not guaranteed to be a valid URL (which means the resulting output
   *     could fail HTML validation). To guarantee a valid URL, use
   *     Url::fromUri($user_input)->toString() (which either throws an exception
   *     or returns a well-formed URL) before passing the result into a
   *     ":variable" placeholder.
   *   - !variable: Inserted as is, with no sanitization or formatting. Only
   *     use this when the resulting string is being generated for one of:
   *     - Non-HTML usage, such as a plain-text email.
   *     - Non-direct HTML output, such as a plain-text variable that will be
   *       printed as an HTML attribute value and therefore formatted with
   *       self::checkPlain() as part of that.
   *     - Some other special reason for suppressing sanitization.
   *
   * @return string
   *   The formatted string, which is marked as safe unless sanitization of an
   *   unsafe argument was suppressed (see above).
   *
   * @ingroup sanitization
   *
   * @see t()
   * @see \Drupal\Component\Utility\Html::escape()
   * @see \Drupal\Component\Utility\UrlHelper::stripDangerousProtocols()
   * @see \Drupal\Core\Url::fromUri()
   */
  public static function format($string, array $args) {
    $safe = TRUE;
    $output = static::placeholderFormat($string, $args, $safe);
    if ($safe) {
      static::$safeStrings[$output]['html'] = TRUE;
    }
    return $output;

  }

}
