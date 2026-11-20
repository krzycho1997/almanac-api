<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing\Recording;

enum Trim
{
    case None;
    case ApodPassthrough;
    case OnThisDayReadFields;

    private const string BASIC_HTML = 'basic_html';
    private const string URL = 'url';
    private const string HD_URL = 'hdurl';
    private const string SERVICE_VERSION = 'service_version';
    private const string API_VERSION = 'v1';
    private const array LISTS = ['selected', 'births', 'deaths'];
    private const string PAGES = 'pages';
    private const string THUMBNAIL = 'thumbnail';
    private const string CONTENT_URLS = 'content_urls';
    private const string DESKTOP = 'desktop';
    private const string PAGE = 'page';
    private const array PAGE_FIELDS = ['type', 'title', 'normalizedtitle', 'description', self::THUMBNAIL, self::CONTENT_URLS, 'wikibase_item'];
    private const array THUMBNAIL_FIELDS = ['source', 'width', 'height'];

    public function apply(mixed $body): mixed
    {
        return match ($this) {
            self::None => $body,
            self::ApodPassthrough => self::apodPassthrough(self::object($body, 'APOD answer')),
            self::OnThisDayReadFields => self::onThisDay(self::object($body, 'On This Day answer')),
        };
    }

    private static function apodPassthrough(\stdClass $body): \stdClass
    {
        $trimmed = clone $body;
        unset($trimmed->{self::BASIC_HTML});
        $trimmed->{self::URL} = self::pictureUrl($trimmed);
        $trimmed->{self::SERVICE_VERSION} = self::API_VERSION;

        return $trimmed;
    }

    private static function pictureUrl(\stdClass $body): mixed
    {
        if (property_exists($body, self::HD_URL)) {
            return $body->{self::HD_URL};
        }

        if (property_exists($body, self::URL)) {
            return $body->{self::URL};
        }

        throw new \UnexpectedValueException('The APOD answer has neither hdurl nor url.');
    }

    private static function onThisDay(\stdClass $body): \stdClass
    {
        $trimmed = new \stdClass();

        foreach (get_object_vars($body) as $list => $entries) {
            $trimmed->{$list} = self::entries((string) $list, $entries);
        }

        return $trimmed;
    }

    /**
     * @return list<\stdClass>
     */
    private static function entries(string $list, mixed $entries): array
    {
        if (\in_array($list, self::LISTS, true)) {
            return array_map(self::entry(...), self::list($entries, "On This Day {$list}"));
        }

        throw new \UnexpectedValueException("The On This Day answer has an unknown list {$list}.");
    }

    private static function entry(mixed $entry): \stdClass
    {
        $trimmed = clone self::object($entry, 'On This Day entry');

        if (property_exists($trimmed, self::PAGES)) {
            $trimmed->{self::PAGES} = array_map(self::page(...), self::list($trimmed->{self::PAGES}, 'On This Day pages'));
        }

        return $trimmed;
    }

    private static function page(mixed $page): \stdClass
    {
        $page = self::object($page, 'On This Day page');
        $trimmed = new \stdClass();

        foreach (self::PAGE_FIELDS as $field) {
            if (property_exists($page, $field)) {
                $trimmed->{$field} = self::field($field, $page->{$field});
            }
        }

        return $trimmed;
    }

    private static function field(string $field, mixed $value): mixed
    {
        return match ($field) {
            self::THUMBNAIL => self::thumbnail(self::object($value, 'On This Day thumbnail')),
            self::CONTENT_URLS => self::desktopPage(self::object($value, 'On This Day content_urls')),
            default => $value,
        };
    }

    private static function thumbnail(\stdClass $thumbnail): \stdClass
    {
        $trimmed = new \stdClass();

        foreach (self::THUMBNAIL_FIELDS as $field) {
            if (property_exists($thumbnail, $field)) {
                $trimmed->{$field} = $thumbnail->{$field};
            }
        }

        return $trimmed;
    }

    private static function desktopPage(\stdClass $contentUrls): \stdClass
    {
        $desktop = self::object($contentUrls->{self::DESKTOP} ?? null, 'On This Day content_urls.desktop');

        if (property_exists($desktop, self::PAGE)) {
            return (object) [self::DESKTOP => (object) [self::PAGE => $desktop->{self::PAGE}]];
        }

        throw new \UnexpectedValueException('The On This Day page has no content_urls.desktop.page.');
    }

    private static function object(mixed $value, string $what): \stdClass
    {
        if ($value instanceof \stdClass) {
            return $value;
        }

        throw new \UnexpectedValueException("The {$what} is not a JSON object.");
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value, string $what): array
    {
        if (\is_array($value)) {
            return array_values($value);
        }

        throw new \UnexpectedValueException("The {$what} is not a JSON list.");
    }
}
