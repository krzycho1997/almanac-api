<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\Model\Attribution;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Model\PageRef;

final class OnThisDayPayloads
{
    public const string CAESAR = 'https://en.wikipedia.org/wiki/Julius_Caesar';
    public const string HAUSER = 'https://en.wikipedia.org/wiki/Wings_Hauser';
    public const string THUMBNAIL = 'https://upload.wikimedia.org/hauser.jpg';

    public static function feed(): OnThisDayFeed
    {
        return new OnThisDayFeed(
            [new HistoricalEvent(-44, 'Julius Caesar is assassinated.', [new PageRef('Julius Caesar', self::CAESAR, self::THUMBNAIL)])],
            [new NotablePerson(1947, 'Wings Hauser', 'American actor', self::HAUSER, self::THUMBNAIL)],
            [new NotablePerson(-44, 'Julius Caesar', null, self::CAESAR, null)],
            new Attribution('Wikipedia', 'https://en.wikipedia.org/', 'CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/', true, null),
        );
    }
}
