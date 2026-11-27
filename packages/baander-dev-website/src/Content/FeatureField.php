<?php

declare(strict_types=1);

namespace Baander\DevWebsite\Content;

/** What one value of a feature data file holds. */
enum FeatureField
{
    /** Plain text, which the template escapes. */
    case Text;
    /** Plain text for search results and link previews, with their length limit. */
    case Description;
    /** Inline HTML, which the template prints as written. */
    case Html;
    /** A lower-case name whose words are joined by hyphens: a page slug, a group, a section or a picture. */
    case Id;
    /** A link to a manual page. */
    case ManualUrl;
    /** Where a tour section shows its figure: beside the text or across the full width. */
    case Layout;
    /** A picture in both appearances, or a block borrowed from a manual page. */
    case Figure;
    /** True or false. */
    case Flag;
}
