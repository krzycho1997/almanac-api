<?php

declare(strict_types=1);

namespace App\Briefing\Source\Apod;

use App\Briefing\Exception\InvalidUpstreamPayloadException;
use App\Briefing\Exception\PayloadDefect;
use App\Briefing\Model\ApodPicture;
use App\Briefing\Model\MediaType;
use App\Briefing\Source\PlainText;
use App\Briefing\Source\WebAddress;

final readonly class ApodMapper
{
    private const string DATE_FORMAT = 'Y-m-d';
    private const string MIDNIGHT_DATE_FORMAT = '!'.self::DATE_FORMAT;
    private const string DATE_TIMEZONE = 'UTC';
    private const string PLACEHOLDER_TITLE = 'NASA Science';
    private const string DATE_FIELD = 'date';
    private const string TITLE_FIELD = 'title';
    private const string EXPLANATION_FIELD = 'explanation';
    private const string EXPLANATION_LABEL = '/^Explanation:\s*/u';
    private const string PAGE_TRAILER = '/(?:^|(?<=[.!?]))\s*(?:Portal Universe|Tomorrow[\'’]s picture):.*$/su';
    private const string BODY = '<body>';
    private const string EMPTY = '';
    private const string GENERIC_THUMBNAIL = '/images/misc/news-thumbnail.png';

    public function map(ApodApiResponse $response): ApodPicture
    {
        return new ApodPicture(
            self::date($response->date),
            self::title($response->title),
            self::explanation($response->explanation),
            self::mediaType($response->mediaType),
            self::mediaUrl($response->url),
            self::mediaUrl($response->hdUrl),
            self::credit($response->copyright),
        );
    }

    private static function title(string $title): string
    {
        $text = self::requiredText(self::plainText($title), self::TITLE_FIELD);

        if (self::PLACEHOLDER_TITLE === $text) {
            throw InvalidUpstreamPayloadException::at(self::TITLE_FIELD, PayloadDefect::Placeholder, 'APOD returned the NASA Science placeholder instead of the picture of the day.');
        }

        return $text;
    }

    private static function date(string $date): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat(self::MIDNIGHT_DATE_FORMAT, $date, new \DateTimeZone(self::DATE_TIMEZONE));

        if (self::isExactly($parsed, $date)) {
            return $parsed;
        }

        throw InvalidUpstreamPayloadException::at(self::DATE_FIELD, PayloadDefect::NotACalendarDate, 'APOD date is not a calendar date in Y-m-d.');
    }

    /**
     * @phpstan-assert-if-true \DateTimeImmutable $parsed
     */
    private static function isExactly(\DateTimeImmutable|false $parsed, string $date): bool
    {
        if ($parsed instanceof \DateTimeImmutable) {
            return $parsed->format(self::DATE_FORMAT) === $date;
        }

        return false;
    }

    private static function requiredText(string $text, string $field): string
    {
        if (self::EMPTY === $text) {
            throw InvalidUpstreamPayloadException::at($field, PayloadDefect::NoText, "APOD {$field} has no text once its HTML is removed.");
        }

        return $text;
    }

    private static function explanation(string $explanation): string
    {
        return self::requiredText(self::withoutLabelAndTrailer(self::plainText($explanation)), self::EXPLANATION_FIELD);
    }

    private static function withoutLabelAndTrailer(string $explanation): string
    {
        return (string) preg_replace([self::EXPLANATION_LABEL, self::PAGE_TRAILER], self::EMPTY, $explanation);
    }

    private static function mediaType(string $mediaType): MediaType
    {
        return MediaType::tryFrom($mediaType) ?? MediaType::Other;
    }

    private static function mediaUrl(?string $url): ?string
    {
        if (self::isMediaAddress((string) $url)) {
            return $url;
        }

        return null;
    }

    private static function isMediaAddress(string $url): bool
    {
        if (self::isGenericThumbnail($url)) {
            return false;
        }

        return WebAddress::isUsable($url);
    }

    private static function isGenericThumbnail(string $url): bool
    {
        return str_ends_with((string) parse_url($url, \PHP_URL_PATH), self::GENERIC_THUMBNAIL);
    }

    private static function credit(?string $credit): ?string
    {
        $plainText = self::plainText($credit ?? self::EMPTY);

        if (self::EMPTY === $plainText) {
            return null;
        }

        return $plainText;
    }

    private static function plainText(string $html): string
    {
        return PlainText::tidy(self::textContent($html));
    }

    private static function textContent(string $html): string
    {
        return \Dom\HTMLDocument::createFromString(self::BODY.$html, \LIBXML_NOERROR)->body->textContent ?? self::EMPTY;
    }
}
