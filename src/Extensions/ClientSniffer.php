<?php

namespace Kodus\Sentry\Extensions;

use Kodus\Sentry\Model\BrowserContext;
use Kodus\Sentry\Model\Event;
use Kodus\Sentry\SentryClientExtension;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Creates the Browser/OS context information based on the `User-Agent` string.
 *
 * @link https://github.com/DamonOehlman/detect-browser
 */
class ClientSniffer implements SentryClientExtension
{
    /**
     * @var string[] map where regular expression pattern => browser name (or "bot")
     */
    public $browser_patterns = [
        "/AOLShield\/([0-9\._]+)/"                           => "aol",
        "/Edge\/([0-9\._]+)/"                                => "edge",
        "/YaBrowser\/([0-9\._]+)/"                           => "yandexbrowser",
        "/Vivaldi\/([0-9\.]+)/"                              => "vivaldi",
        "/KAKAOTALK\s([0-9\.]+)/"                            => "kakaotalk",
        "/SamsungBrowser\/([0-9\.]+)/"                       => "samsung",
        "/(?!Chrom.*OPR)Chrom(?:e|ium)\/([0-9\.]+)(:?\s|$)/" => "chrome",
        "/PhantomJS\/([0-9\.]+)(:?\s|$)/"                    => "phantomjs",
        "/CriOS\/([0-9\.]+)(:?\s|$)/"                        => "crios",
        "/Firefox\/([0-9\.]+)(?:\s|$)/"                      => "firefox",
        "/FxiOS\/([0-9\.]+)/"                                => "fxios",
        "/Opera\/([0-9\.]+)(?:\s|$)/"                        => "opera",
        "/OPR\/([0-9\.]+)(:?\s|$)$/"                         => "opera",
        "/Trident\/7\.0.*rv\:([0-9\.]+).*\).*Gecko$/"        => "ie",
        "/MSIE\s([0-9\.]+);.*Trident\/[4-7].0/"              => "ie",
        "/MSIE\s(7\.0)/"                                     => "ie",
        "/BB10;\sTouch.*Version\/([0-9\.]+)/"                => "bb10",
        "/Android\s([0-9\.]+)/"                              => "android",
        "/Version\/([0-9\._]+).*Mobile.*Safari.*/"           => "ios",
        "/Version\/([0-9\._]+).*Safari/"                     => "safari",
        "/FBAV\/([0-9\.]+)/"                                 => "facebook",
        "/Instagram\s([0-9\.]+)/"                            => "instagram",
        "/AppleWebKit\/([0-9\.]+).*Mobile/"                  => "ios-webview",

        "/(nuhk|slurp|ask jeeves\/teoma|ia_archiver|alexa|crawl|crawler|crawling|facebookexternalhit|feedburner|google web preview|nagios|postrank|pingdom|slurp|spider|yahoo!|yandex|\w+bot)/i" => "bot",
    ];

    /**
     * @var string[] map where regular expression pattern => OS name
     */
    public $os_patterns = [
        "/iP(hone|od|ad)/"                    => "iOS",
        "/Android/"                           => "Android OS",
        "/BlackBerry|BB10/"                   => "BlackBerry OS",
        "/IEMobile/"                          => "Windows Mobile",
        "/Kindle/"                            => "Amazon OS",
        "/Win16/"                             => "Windows 3.11",
        "/(Windows 95)|(Win95)|(Windows_95)/" => "Windows 95",
        "/(Windows 98)|(Win98)/"              => "Windows 98",
        "/(Windows NT 5.0)|(Windows 2000)/"   => "Windows 2000",
        "/(Windows NT 5.1)|(Windows XP)/"     => "Windows XP",
        "/(Windows NT 5.2)/"                  => "Windows Server 2003",
        "/(Windows NT 6.0)/"                  => "Windows Vista",
        "/(Windows NT 6.1)/"                  => "Windows 7",
        "/(Windows NT 6.2)/"                  => "Windows 8",
        "/(Windows NT 6.3)/"                  => "Windows 8.1",
        "/(Windows NT 10.0)/"                 => "Windows 10",
        "/Windows ME/"                        => "Windows ME",
        "/OpenBSD/"                           => "Open BSD",
        "/SunOS/"                             => "Sun OS",
        "/(Linux)|(X11)/"                     => "Linux",
        "/(Mac_PowerPC)|(Macintosh)/"         => "Mac OS",
        "/QNX/"                               => "QNX",
        "/BeOS/"                              => "BeOS",
        "/OS\/2/"                             => "OS/2",
    ];

    public function apply(Event $event, Throwable $exception, ?ServerRequestInterface $request): void
    {
        if (! $request || ! $request->hasHeader("User-Agent")) {
            return;
        }

        $user_agent = $request->getHeaderLine("User-Agent");

        $browser_name = "unknown";

        foreach ($this->browser_patterns as $pattern => $name) {
            if (preg_match($pattern, $user_agent, $browser_matches) === 1) {
                $browser_name = $name;

                break;
            }
        }

        $browser_version = isset($browser_matches[1])
            ? strtolower(implode(".", preg_split('/[._]/', $browser_matches[1])))
            : "unknown";

        $event->addTag("browser.{$browser_name}", $browser_version);

        $browser_os = "unknown";

        if ($browser_name !== "bot" && $browser_name !== "unknown") {
            foreach ($this->os_patterns as $pattern => $os) {
                if (preg_match($pattern, $user_agent) === 1) {
                    $browser_os = $os;

                    break;
                }
            }
        }

        $event->addTag("browser.os", $browser_os);

        $context = $browser_name === "bot"
            ? new BrowserContext("{$browser_name}/{$browser_version}", null)
            : new BrowserContext(
                $browser_name,
                $browser_version === "unknown"
                    ? $user_agent // TODO maybe fall back on a User-Agent hash for brevity?
                    : "{$browser_version}/{$browser_os}");

        $event->addContext($context);
    }
}
