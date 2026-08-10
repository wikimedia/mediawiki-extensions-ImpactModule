<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Aqs;

enum AqsPageType: string {
	case All = 'all_page_types';
	case Content = 'content';
	case NonContent = 'non_content';
}
