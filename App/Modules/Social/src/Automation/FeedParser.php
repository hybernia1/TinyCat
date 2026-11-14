<?php
declare(strict_types=1);
namespace TinyCat\Social\Automation;

// Compatibility for existing integrations; implementation belongs to Publishing.
class_alias(\TinyCat\Core\Feeds\FeedParser::class, __NAMESPACE__ . '\\FeedParser');
