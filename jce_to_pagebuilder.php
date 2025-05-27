<?php
if (!defined('_JEXEC')) {
    define('_JEXEC', 1);
}

define('JPATH_BASE', __DIR__);
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

// Get the database object only
$db = \Joomla\CMS\Factory::getDbo();

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create logs directory if it doesn't exist
$logDir = JPATH_BASE . '/logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}

class ArticleConverter
{
    protected $db;
    protected $logFile;
    protected $joomlaVersion;
    
    public function __construct()
    {
        $this->db = \Joomla\CMS\Factory::getDbo();
        $this->logFile = JPATH_ROOT . '/logs/pagebuilder_conversion_' . date('Y-m-d') . '.log';
        $this->joomlaVersion = $this->getJoomlaVersion();
        
        // Clear log file at start
        file_put_contents($this->logFile, '');
    }

    protected function getJoomlaVersion()
    {
        if (!isset($this->joomlaVersion)) {
            $manifest = simplexml_load_file(JPATH_ADMINISTRATOR . '/manifests/files/joomla.xml');
            $this->joomlaVersion = (string)$manifest->version;
        }
        return $this->joomlaVersion;
    }

    public function convert()
    {
        $this->log("Starting conversion process");
        
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['id', 'title', 'introtext', 'fulltext']))
            ->from($this->db->quoteName('#__content'))
            ->where($this->db->quoteName('fulltext') . ' = ' . $this->db->quote(''))
            ->where($this->db->quoteName('introtext') . ' != ' . $this->db->quote(''));
        
        $this->db->setQuery($query);
        $this->log("Executing query: " . $query->dump());
        
        try {
            $articles = $this->db->loadObjectList();
            $this->log("Found " . count($articles) . " articles to convert");
            
            foreach ($articles as $article) {
                $this->convertSingleArticle($article);
            }
            
            return true;
        } catch (Exception $e) {
            $this->log("Error: " . $e->getMessage(), 'error');
            return false;
        }
    }

    protected function convertSingleArticle($article)
    {
        $this->log("Converting article ID {$article->id}: {$article->title}");
        $this->log("Original content length: " . strlen($article->introtext));
        
        // Backup original content
        $this->backupArticle($article->id);
        
        // Parse the content and create appropriate elements
        // $elements = $this->parseContentIntoElements($article->introtext);

        // --- New DOM parsing logic ---
        $introText = $article->introtext;
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        // Wrap content to ensure proper parsing if it's a fragment
        $wrappedContent = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $introText . '</body></html>';
        $dom->preserveWhiteSpace = true;
        $dom->loadHTML($wrappedContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $bodyNode = $dom->getElementsByTagName('body')->item(0);

        if (!$bodyNode) {
            $this->log("Error: Could not find body element in introtext for article ID {$article->id}", 'error');
            // Handle error appropriately, perhaps return false or an empty array
            $elements = [];
        } else {
            $elements = $this->parseContentIntoElements($bodyNode);
        }
        // --- End new DOM parsing logic ---
        
        $this->log("Number of elements created: " . count($elements));
        
        if (empty($elements)) {
            $this->log("No elements were created for article {$article->id}, skipping", 'warning');
            return false;
        }
        
        // Create Pagebuilder structure
        $pagebuilderData = [
            "type" => "layout",
            "children" => [
                [
                    "type" => "section",
                    "props" => [
                        "style" => "default",
                        "width" => "default",
                        "vertical_align" => "middle",
                        "title_position" => "top-left",
                        "title_rotation" => "left",
                        "title_breakpoint" => "xl",
                        "image_position" => "center-center",
                        "padding_custom_bottom" => "40",
                        "padding_custom_top" => "40"
                    ],
                    "children" => [
                        [
                            "type" => "row",
                            "children" => [
                                [
                                    "type" => "column",
                                    "props" => [
                                        "image_position" => "center-center",
                                        "position_sticky_breakpoint" => "m"
                                    ],
                                    "children" => $elements
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            "version" => "4.2.12",
            "yooessentialsVersion" => "2.2.0"
        ];

        // Convert to HTML comment format
        $pagebuilderContent = '<!-- ' . json_encode($pagebuilderData) . ' -->';
        $this->log("Generated pagebuilder content length: " . strlen($pagebuilderContent));

        try {
			// Remove any existing readmore tags before saving
			$pagebuilderContent = preg_replace('/<hr(.*)id="system-readmore"(.*)\/>/i', '', $pagebuilderContent);
			
			$query = $this->db->getQuery(true)
				->update($this->db->quoteName('#__content'))
				->set($this->db->quoteName('fulltext') . ' = ' . $this->db->quote($pagebuilderContent))
				->where($this->db->quoteName('id') . ' = ' . (int)$article->id);
	
			$this->db->setQuery($query);
			$this->db->execute();
			
			// Also clear the introtext to prevent readmore being added there
			// $query = $this->db->getQuery(true)
			// 	->update($this->db->quoteName('#__content'))
			// 	->set($this->db->quoteName('introtext') . ' = ' . $this->db->quote(''))
			// 	->where($this->db->quoteName('id') . ' = ' . (int)$article->id);
				
			// $this->db->setQuery($query);
			// $this->db->execute();
	
			$this->log("Successfully updated article ID {$article->id} (introtext preserved).");
			return true;
		} catch (Exception $e) {
			$this->log("Error updating article ID {$article->id}: " . $e->getMessage(), 'error');
			return false;
		}
	}


    protected function createImageElementProperties(DOMNode $imgNode, $figcaptionText = null)
    {
        $originalSrc = $imgNode->getAttribute('src');
        $this->log("Creating image properties for original src: " . $originalSrc);

        $processedSrc = $this->processImageSrc($originalSrc);

        $altText = $imgNode->getAttribute('alt') ?: '';
        $titleText = $imgNode->getAttribute('title') ?: '';

        if ($figcaptionText && !empty(trim($figcaptionText))) {
            $caption = trim($figcaptionText);
            if (empty($titleText)) {
                $titleText = $caption;
                $this->log("Using figcaption for image title: " . $caption);
            } elseif (empty($altText)) {
                $altText = $caption;
                $this->log("Using figcaption for image alt: " . $caption);
            } else {
                // If both are filled, append to title as per previous logic
                $titleText .= " (" . $caption . ")";
                $this->log("Appending figcaption to image title. New title: " . $titleText);
            }
        }

        $width = $imgNode->getAttribute('width') ?: '';
        $height = $imgNode->getAttribute('height') ?: '';

        $style = $imgNode->getAttribute('style');
        $alignment = 'none';
        $marginRight = '';
        $marginLeft = '';

        if (strpos($style, 'float: right') !== false) {
            $alignment = 'right';
            if (preg_match('/margin-left\s*:\s*([^;]+)/i', $style, $matches)) {
                // Basic check for common margin unit, could be more robust
                if (strpos($matches[1], 'px') !== false || strpos($matches[1], 'em') !== false || strpos($matches[1], 'rem') !== false) {
                     $marginLeft = 'medium'; // Placeholder, actual mapping might be more complex
                     $this->log("Detected float right with margin-left, setting YOOtheme margin_left: medium");
                }
            }
        } elseif (strpos($style, 'float: left') !== false) {
            $alignment = 'left';
            if (preg_match('/margin-right\s*:\s*([^;]+)/i', $style, $matches)) {
                 if (strpos($matches[1], 'px') !== false || strpos($matches[1], 'em') !== false || strpos($matches[1], 'rem') !== false) {
                    $marginRight = 'medium'; // Placeholder
                    $this->log("Detected float left with margin-right, setting YOOtheme margin_right: medium");
                }
            }
        }
        
        $props = [
            "image"           => $originalSrc, // Keep original path for YOOtheme Pro
            // "src"             => $processedSrc, // Remove processed/absolute src
            "alt"             => $altText,
            "title"           => $titleText,
            "width"           => $width,
            "height"          => $height,
            "image_width"     => $width ? intval($width) : null,
            "image_height"    => $height ? intval($height) : null,
            "image_size"      => ($width && $height) ? 'auto' : 'auto', // YOOtheme usually defaults to auto or handles it.
            "image_svg_color" => "emphasis", // Default
            "margin"          => "default",    // Default
            "uk_img"          => true,         // Default
            "position"        => $alignment,
            "margin_right"    => $marginRight,
            "margin_left"     => $marginLeft
        ];
        
        $this->log("Created image properties: " . json_encode($props));
        return $props;
    }

    protected function getTextElementStyleProps(DOMNode $styledNode, $logContext = '') {
        $textProps = [];
        $nodeClassesStr = $styledNode->getAttribute('class');
        if (empty($nodeClassesStr)) {
            return $textProps; // No classes to process
        }
        $nodeClasses = explode(' ', $nodeClassesStr);
        $appliedStyles = [];

        $logNodeName = $styledNode->nodeName;
        $logPrefix = "Styling from {$logNodeName}" . ($logContext ? " ({$logContext})" : "") . ": ";

        // Text Alignment
        if (in_array('text-center', $nodeClasses) || in_array('uk-text-center', $nodeClasses)) {
            $textProps['text_align'] = 'center';
            $appliedStyles[] = 'text_align:center';
        } elseif (in_array('text-right', $nodeClasses) || in_array('uk-text-right', $nodeClasses)) {
            $textProps['text_align'] = 'right';
            $appliedStyles[] = 'text_align:right';
        } elseif (in_array('text-justify', $nodeClasses) || in_array('uk-text-justify', $nodeClasses)) {
            $textProps['text_align'] = 'justify';
            $appliedStyles[] = 'text_align:justify';
        }

        // Text Style (Lead)
        if (in_array('lead', $nodeClasses) || in_array('uk-text-lead', $nodeClasses)) {
            $textProps['text_style'] = 'lead'; // YOOtheme Pro typically uses 'lead' for this
            $appliedStyles[] = 'text_style:lead';
        }
        
        // Font Size (Small) - YOOtheme Pro might handle this with utility classes or specific text style props.
        // Adding to html_class is a common way if direct prop isn't available.
        if (in_array('uk-text-small', $nodeClasses)) {
            $textProps['html_class'] = ($textProps['html_class'] ?? '') . ' uk-text-small';
            $appliedStyles[] = 'html_class:uk-text-small (for font_size:small)';
        }

        // Emphasis - Similar to small, could be a class or a YOOtheme Pro text style.
        if (in_array('uk-text-emphasis', $nodeClasses)) {
            if (!isset($textProps['text_style']) || $textProps['text_style'] !== 'lead') { // Don't override lead with emphasis if both are present
                // YOOtheme Pro might have an 'emphasis' option for 'text_style', or use a class.
                // $textProps['text_style'] = 'emphasis'; // If direct prop
                $textProps['html_class'] = ($textProps['html_class'] ?? '') . ' uk-text-emphasis'; // If class-based
                $appliedStyles[] = 'html_class:uk-text-emphasis (for emphasis)';
            }
        }
        
        // Clean up html_class if it was added and is only spaces
        if (isset($textProps['html_class'])) {
            $textProps['html_class'] = trim($textProps['html_class']);
            if (empty($textProps['html_class'])) {
                unset($textProps['html_class']);
            }
        }

        if (!empty($appliedStyles)) {
            $this->log($logPrefix . implode(', ', $appliedStyles) . ". Original classes: '" . $nodeClassesStr . "'", 'debug');
        }
        return $textProps;
    }

    protected function parseContentIntoElements(DOMNode $parentNode)
	{
		$this->log("Preparing to parse children of node: {$parentNode->nodeName} with text coalescing logic");
		
		$elements = [];
        $textBuffer = '';
        $definedButtonClasses = ['btn', 'button', 'uk-button', 'uk-button-primary', 'uk-button-secondary', 'uk-button-danger', 'uk-button-text', 'uk-button-link', 'uk-button-large', 'uk-button-small', 'uk-width-1-1'];
        $inlineTags = ['strong', 'em', 'b', 'i', 'u', 'span', 'br', 'code', 'small', 'mark', 'sub', 'sup', 'font', 'abbr', 'acronym', 'cite', 'del', 'ins', 'q', 's', 'samp', 'var', 'time', 'kbd']; // Added more common inline tags

		foreach ($parentNode->childNodes as $node) {
            // A.1 Handle Text Nodes
			if ($node->nodeType === XML_TEXT_NODE) {
				// $this->log("Node is XML_TEXT_NODE, appending to textBuffer. Current buffer length: " . strlen($textBuffer), 'debug');
				$textBuffer .= $node->nodeValue;
				// $this->log("Appended nodeValue. New buffer length: " . strlen($textBuffer), 'debug');
				continue;
			}

            // Skip comments, PIs, etc.
			if ($node->nodeType !== XML_ELEMENT_NODE) {
                // $this->log("Node is not XML_ELEMENT_NODE or XML_TEXT_NODE (type: {$node->nodeType}). Skipping.", 'debug');
				continue;
			}
			
            $nodeNameLower = strtolower($node->nodeName);
            // $this->log("Processing element node: {$nodeNameLower}. Current textBuffer: '" . substr($textBuffer,0,30) . "...'", 'debug');

            // A.2 Handle common inline HTML elements
            if (in_array($nodeNameLower, $inlineTags)) {
                $inlineHtml = $node->ownerDocument->saveHTML($node);
                // $this->log("Node {$nodeNameLower} is inline, appending its HTML to textBuffer. HTML: " . $inlineHtml, 'debug');
                $textBuffer .= $inlineHtml;
                continue;
            }

            // A.3 Special Handling for 'a' tags (button vs inline link)
            if ($nodeNameLower === 'a') {
                $is_a_button = false;
                $a_node_classes_str = $node->getAttribute('class');
                $a_node_classes = explode(' ', $a_node_classes_str);
                foreach ($definedButtonClasses as $btnClass) {
                    if (in_array($btnClass, $a_node_classes)) {
                        $is_a_button = true;
                        break;
                    }
                }

                if ($is_a_button) {
                    $this->log("Node <a> is a button. Flushing textBuffer first.", 'debug');
                    // B. Flush $textBuffer if not empty
                    $trimmedTextBuffer = trim($textBuffer);
                    if ($trimmedTextBuffer !== '') {
                        $textElementProps = array_merge(
                            ["column_breakpoint" => "m", "margin" => "default", "content" => $trimmedTextBuffer],
                            $this->getTextElementStyleProps($parentNode, 'parent of buffered text before button')
                        );
                        $elements[] = ["type" => "text", "props" => $textElementProps];
                        $this->log("Added coalesced text element (before button), possibly with parent styling: " . substr($trimmedTextBuffer, 0, 50) . "...");
                    }
                    $textBuffer = ''; // Reset buffer

                    // Process button <a>
                    $this->log("<a> tag with href '" . $node->getAttribute('href') . "' has button class(es) '" . $a_node_classes_str . "'. Converting to button element.");
                    $buttonProps = [
                        "button_style" => "default", "link_title" => trim($this->getInnerHTML($node)),
                        "link_href" => $node->getAttribute('href') ?: '#', "margin" => "default"
                    ];
                    if (in_array('uk-button-primary', $a_node_classes)) $buttonProps['button_style'] = 'primary';
                    elseif (in_array('uk-button-secondary', $a_node_classes)) $buttonProps['button_style'] = 'secondary';
                    // ... other button style mappings ...
                    if (in_array('uk-button-large', $a_node_classes)) $buttonProps['button_size'] = 'large';
                    if (in_array('uk-button-small', $a_node_classes)) $buttonProps['button_size'] = 'small';
                    if (in_array('uk-width-1-1', $a_node_classes)) $buttonProps['full_width'] = true;
                    $elements[] = ["type" => "button", "props" => $buttonProps];
                    $this->log("Converted <a> tag to page builder 'button'. Title: '" . $buttonProps['link_title'] . "'");
                } else { // Is an inline link
                    $inlineLinkHtml = $node->ownerDocument->saveHTML($node);
                    // $this->log("Node <a> is an inline link, appending its HTML to textBuffer. HTML: " . $inlineLinkHtml, 'debug');
                    $textBuffer .= $inlineLinkHtml;
                }
                continue; 
            }

            // B. Handling Block-Level/Complex Elements (and Flushing Buffer)
            // If we reach here, it's a block-level or complex element.
            // Time to flush any accumulated text.
            $trimmedTextBufferOnBlock = trim($textBuffer);
            if ($trimmedTextBufferOnBlock !== '') {
                $this->log("Flushing text buffer before processing block element: {$nodeNameLower}. Buffer content: " . substr($trimmedTextBufferOnBlock, 0, 50) . "...", 'debug');
                $textElementProps = array_merge(
                    ["column_breakpoint" => "m", "margin" => "default", "content" => $trimmedTextBufferOnBlock],
                    $this->getTextElementStyleProps($parentNode, 'parent of buffered text before ' . $nodeNameLower)
                );
                $elements[] = ["type" => "text", "props" => $textElementProps];
                $this->log("Added coalesced text element from buffer (before {$nodeNameLower}), possibly with parent styling.");
            }
            $textBuffer = ''; // Reset buffer

            // Existing logic for block-level elements
			$nodeContent = trim($this->getInnerHTML($node)); // Used by some cases like table, blockquote
			$textContent = trim($node->textContent); // Used by h1-h6

			// Skip if both innerHTML and textContent are empty (applies to block elements being processed now)
            // This check might be less relevant now with text coalescing, but keeping for safety for cases that rely on $nodeContent or $textContent.
			if (empty($nodeContent) && empty($textContent) && !in_array($nodeNameLower, ['img'])) { // IMG can be empty of textContent but have src
				$this->log("Skipping empty block node after buffer flush: " . $nodeNameLower, 'debug');
				continue; 
			}

			switch ($nodeNameLower) {
				case 'h1':
				case 'h2':
				case 'h3':
				case 'h4':
				case 'h5':
				case 'h6':
					if (!empty($textContent)) {
						$elements[] = [
							"type" => "headline",
							"props" => [
								"title_element" => $node->nodeName,
								"content" => $textContent
							]
						];
						$this->log("Added headline element: " . $node->nodeName);
					}
					break;
	
				case 'div':
					$this->log("Processing div node with class: " . $node->getAttribute('class'));
					
					// Recursively parse children of the div
					$nestedElements = $this->parseContentIntoElements($node); // Pass the div itself as the new parent
					
					if (!empty($nestedElements)) {
						$elements = array_merge($elements, $nestedElements);
						$this->log("Added " . count($nestedElements) . " elements from div's children.");
					} else {
						$this->log("Div node (class: " . $node->getAttribute('class') . ") resulted in no elements after parsing its children.");
					}
					break;
                
                case 'article':
                case 'section':
                case 'aside':
                case 'footer':
                case 'header':
                    $this->log("Processing " . $node->nodeName . " node with class: " . $node->getAttribute('class'));
                    $nestedElements = $this->parseContentIntoElements($node); // Pass the node itself as the new parent
                    if (!empty($nestedElements)) {
                        $elements = array_merge($elements, $nestedElements);
                        $this->log("Added " . count($nestedElements) . " elements from " . $node->nodeName . "'s children.");
                    } else {
                        $this->log($node->nodeName . " node (class: " . $node->getAttribute('class') . ") resulted in no elements after parsing its children.");
                    }
                    break;

                case 'figure':
                    $this->log("Processing figure node.");
                    $imgNode = null;
                    $figcaptionNode = null;
                    $otherContent = false;

                    foreach ($node->childNodes as $childNode) {
                        if ($childNode->nodeType === XML_ELEMENT_NODE) {
                            if (strtolower($childNode->nodeName) === 'img') {
                                if ($imgNode === null) { // Process first image found
                                    $imgNode = $childNode;
                                } else {
                                    $otherContent = true; // More than one image or other elements
                                }
                            } elseif (strtolower($childNode->nodeName) === 'figcaption') {
                                if ($figcaptionNode === null) { // Process first figcaption
                                    $figcaptionNode = $childNode;
                                } else {
                                    $otherContent = true; // More than one figcaption
                                }
                            } elseif (trim($childNode->textContent) !== '') {
                                $otherContent = true; // Other non-empty element nodes
                            }
                        } elseif ($childNode->nodeType === XML_TEXT_NODE && trim($childNode->nodeValue) !== '') {
                            $otherContent = true; // Or non-empty text nodes
                        }
                    }

                    if ($imgNode && !$otherContent) { // Prioritize image extraction if an img is present and no other significant content
                        $this->log("Found img element within figure.");
                        $src = $imgNode->getAttribute('src');
                        if (!empty($src)) {
                            $originalSrc = $src;
                            $style = $imgNode->getAttribute('style');
                            $alignment = 'none';
                            $marginRight = '';
                            $marginLeft = '';
                            if (strpos($style, 'float: right') !== false) { $alignment = 'right'; if (strpos($style, 'margin-right') !== false) { $marginRight = 'medium'; }}
                            elseif (strpos($style, 'float: left') !== false) { $alignment = 'left'; if (strpos($style, 'margin-left') !== false) { $marginLeft = 'medium'; }}
                            $width = $imgNode->getAttribute('width') ?: '';
                            $height = $imgNode->getAttribute('height') ?: '';
                            if (strpos($src, 'http') !== 0 && strpos($src, '//') !== 0) {
                                $src = ltrim($src, '/');
                                $siteUrl = \Joomla\CMS\Uri\Uri::root();
                                $src = $siteUrl . $src;
                            }
                            $altText = $imgNode->getAttribute('alt') ?: '';
                            $titleText = $imgNode->getAttribute('title') ?: '';

                            $captionTextForHelper = $figcaptionNode ? trim($figcaptionNode->textContent) : null;
                            $imageProps = $this->createImageElementProperties($imgNode, $captionTextForHelper);
                            $elements[] = ["type" => "image", "props" => $imageProps];
                            // Log message now part of createImageElementProperties
                        } else {
                             $this->log("Image in figure has empty src, falling back to recursive parsing for figure.", 'warning');
                             $nestedElements = $this->parseContentIntoElements($node); // $node here is the <figure> element
                             if (!empty($nestedElements)) { 
                                 $elements = array_merge($elements, $nestedElements); 
                                 $this->log("Added " . count($nestedElements) . " elements from figure's children recursively after empty src image."); 
                             }
                        }
                    } else {
                        if ($imgNode && $otherContent) {
                            $this->log("Figure contains an image but also other complex content. Parsing all children recursively.");
                        } elseif (!$imgNode) {
                            $this->log("No img found in figure, or figure is empty. Parsing children recursively (if any).");
                        }
                        $nestedElements = $this->parseContentIntoElements($node);
                        if (!empty($nestedElements)) {
                            $elements = array_merge($elements, $nestedElements);
                            $this->log("Added " . count($nestedElements) . " elements from figure's children (fallback/complex content).");
                        } else {
                             $this->log("Figure node resulted in no elements after recursive parsing (fallback/complex content).");
                        }
                    }
                    break;

                case 'nav':
                    $this->log("Processing nav node.");
                    $foundList = false;
                    foreach ($node->childNodes as $childNode) {
                        if ($childNode->nodeType === XML_ELEMENT_NODE && (strtolower($childNode->nodeName) === 'ul' || strtolower($childNode->nodeName) === 'ol')) {
                            $this->log("Found " . $childNode->nodeName . " list inside nav. Processing as list.");
                            // Simplified list processing for nav - directly using the ul/ol case logic by creating a temporary DOM for the list node
                            // This is a bit of a workaround. A helper function `parseSingleNodeAsList` would be cleaner.
                            // For now, we'll just extract list items directly.
                            $listItems = $childNode->getElementsByTagName('li');
                            $items = [];
                            foreach ($listItems as $li) {
                                // Try to find a link within the li
                                $linkNode = null;
                                foreach($li->childNodes as $liChild) {
                                    if ($liChild->nodeType === XML_ELEMENT_NODE && strtolower($liChild->nodeName) === 'a') {
                                        $linkNode = $liChild;
                                        break;
                                    }
                                }

                                if ($linkNode) {
                                     $items[] = [
                                        "type" => "list_item", // Or a more specific nav_item if schema allows
                                        "props" => [
                                            "content" => trim($this->getInnerHTML($linkNode)), // Changed to getInnerHTML
                                            "link_href" => $linkNode->getAttribute('href'), // Link href
                                            // Potentially add other link attributes if needed
                                        ]
                                    ];
                                } else {
                                     $items[] = [ // Fallback for li without a direct link
                                        "type" => "list_item",
                                        "props" => [
                                            "content" => trim($this->getInnerHTML($li)) // Changed to getInnerHTML
                                        ]
                                    ];
                                }
                            }
                            if (!empty($items)) {
                                $elements[] = [
                                    "type" => "list", // Or "menu" / "navigation" if available
                                    "props" => [
                                        "column_breakpoint" => "m", "image_align" => "left", "image_svg_color" => "emphasis",
                                        "image_vertical_align" => true, "list_element" => $childNode->nodeName,
                                        "list_horizontal_separator" => ", ", "list_type" => "vertical",
                                        "show_image" => true, "show_link" => true
                                        // Potentially add nav-specific props
                                    ],
                                    "children" => $items
                                ];
                                $this->log("Added list element from nav with " . count($items) . " items.");
                                $foundList = true;
                            }
                            break; // Process first found list and then stop for this nav
                        }
                    }

                    if (!$foundList) {
                        $this->log("No direct ul/ol found in nav, or list was empty. Parsing children recursively.");
                        $nestedElements = $this->parseContentIntoElements($node);
                        if (!empty($nestedElements)) {
                            $elements = array_merge($elements, $nestedElements);
                            $this->log("Added " . count($nestedElements) . " elements from nav's children (fallback).");
                        } else {
                            $this->log("Nav node resulted in no elements after recursive parsing (fallback).");
                        }
                    }
                    break;

    			case 'p':
                    $this->log("Processing p node.");
                    $childNodes = [];
                    foreach ($node->childNodes as $child) {
                        if ($child->nodeType === XML_ELEMENT_NODE) {
                            $childNodes[] = $child;
                        } elseif ($child->nodeType === XML_TEXT_NODE && trim($child->nodeValue) !== '') {
                            // If there's significant text content alongside an element, it's not a "single child" case
                            $childNodes[] = $child; // Add it to consider for length check
                        }
                    }
    
                    // 1. Single Image Check
                    if (count($childNodes) === 1 && strtolower($childNodes[0]->nodeName) === 'img') {
                        $imgNode = $childNodes[0];
                        $this->log("Paragraph contains a single image.");
                        $src = $imgNode->getAttribute('src');
                        if (!empty($src)) {
                            $originalSrc = $src;
                            $style = $imgNode->getAttribute('style');
                            $alignment = 'none'; $marginRight = ''; $marginLeft = '';
                            if (strpos($style, 'float: right') !== false) { $alignment = 'right'; if (strpos($style, 'margin-right') !== false) $marginRight = 'medium'; } 
                            elseif (strpos($style, 'float: left') !== false) { $alignment = 'left'; if (strpos($style, 'margin-left') !== false) $marginLeft = 'medium'; }
                            
                            if (strpos($src, 'http') !== 0 && strpos($src, '//') !== 0) {
                                $src = ltrim($src, '/');
                                $siteUrl = \Joomla\CMS\Uri\Uri::root();
                                $src = $siteUrl . $src;
                            }
                            $imageProps = $this->createImageElementProperties($imgNode);
                            $elements[] = ["type" => "image", "props" => $imageProps];
                            // Log message now part of createImageElementProperties
                        } else {
                            $this->log("Paragraph with single image has empty src. Skipping direct image conversion, will be handled by parent or default.", 'warning');
                            // Potentially fall back to processing the paragraph as text if the image src is empty
                            // This case might need review if <p><img src=""></p> should produce an empty text element or nothing.
                            // For now, it produces nothing if src is empty.
                        }
                    // 2. Button Detection
                    } elseif (count($childNodes) === 1 && strtolower($childNodes[0]->nodeName) === 'a') {
                        $linkNode = $childNodes[0];
                        $linkClasses = explode(' ', $linkNode->getAttribute('class'));
                        $buttonClasses = ['btn', 'button', 'uk-button'];
                        $isButton = false;
                        foreach ($buttonClasses as $btnClass) {
                            if (in_array($btnClass, $linkClasses)) {
                                $isButton = true;
                                break;
                            }
                        }
    
                        if ($isButton) {
                            $this->log("Paragraph contains a link with button class(es). Converting to button element.");
                            $buttonProps = [
                                "button_style" => "default", // Default YOOtheme Pro style
                                "link_title" => trim($this->getInnerHTML($linkNode)), // Use innerHTML for rich content like icons
                                "link_href" => $linkNode->getAttribute('href') ?: '#',
                                "margin" => "default"
                            ];
                            // Map common button style classes
                            if (in_array('uk-button-primary', $linkClasses)) $buttonProps['button_style'] = 'primary';
                            if (in_array('uk-button-secondary', $linkClasses)) $buttonProps['button_style'] = 'secondary';
                            if (in_array('uk-button-danger', $linkClasses)) $buttonProps['button_style'] = 'danger';
                            if (in_array('uk-button-text', $linkClasses)) $buttonProps['button_style'] = 'text';
                            if (in_array('uk-button-link', $linkClasses)) $buttonProps['button_style'] = 'link'; // Note: YOOtheme might have 'text' or specific link style

                            // Map size classes
                            if (in_array('uk-button-small', $linkClasses)) $buttonProps['button_size'] = 'small';
                            if (in_array('uk-button-large', $linkClasses)) $buttonProps['button_size'] = 'large';
                            
                            // Full width
                            if (in_array('uk-width-1-1', $linkClasses)) $buttonProps['full_width'] = true;

                            $elements[] = [
                                "type" => "button",
                                "props" => $buttonProps
                            ];
                            $this->log("Converted paragraph to button: " . $buttonProps['link_title']);
                        } else {
                             // It's a paragraph with a single link, but not styled as a button - treat as regular text
                            $this->log("Paragraph with a single link (not a button) - converting to text element.");
                            goto regular_paragraph_processing;
                        }
                    // 3. Else (default for <p>) -> create "text" element, applying class-based styling
                    } else {
                        regular_paragraph_processing: // Label for goto
                        $this->log("Paragraph treated as regular text content, applying styles from <p> itself.");
                        $content = $this->getInnerHtml($node); // $node is the <p>
                        if (trim($content) !== '') {
                            $textElementProps = array_merge(
                                ["column_breakpoint" => "m", "margin" => "default", "content" => $content],
                                $this->getTextElementStyleProps($node, 'paragraph node') // Pass the <p> node itself
                            );
                            $elements[] = ["type" => "text", "props" => $textElementProps];
                            // Logging for applied styles is now inside getTextElementStyleProps
                        } else {
                            $this->log("Paragraph is empty after trimming, skipping.", 'debug');
                        }
                    }
                    break;

                case 'a':
                    $this->log("Processing a node: " . $node->getAttribute('href'));
                    $a_node_classes_str = $node->getAttribute('class');
                    $a_node_classes = explode(' ', $a_node_classes_str);
                    $is_a_button = false;
                    foreach ($definedButtonClasses as $btnClass) {
                        if (in_array($btnClass, $a_node_classes)) {
                            $is_a_button = true;
                            break;
                        }
                    }

                    if ($is_a_button) {
                        $this->log("<a> tag with href '" . $node->getAttribute('href') . "' has button class(es) '" . $a_node_classes_str . "'. Converting to button element.");
                        $buttonProps = [
                            "button_style" => "default",
                            "link_title" => trim($this->getInnerHTML($node)),
                            "link_href" => $node->getAttribute('href') ?: '#',
                            "margin" => "default" // Standard margin for standalone buttons
                        ];
                        if (in_array('uk-button-primary', $a_node_classes)) $buttonProps['button_style'] = 'primary';
                        elseif (in_array('uk-button-secondary', $a_node_classes)) $buttonProps['button_style'] = 'secondary';
                        elseif (in_array('uk-button-danger', $a_node_classes)) $buttonProps['button_style'] = 'danger';
                        elseif (in_array('uk-button-text', $a_node_classes)) $buttonProps['button_style'] = 'text';
                        elseif (in_array('uk-button-link', $a_node_classes)) $buttonProps['button_style'] = 'link';
                        
                        if (in_array('uk-button-large', $a_node_classes)) $buttonProps['button_size'] = 'large';
                        if (in_array('uk-button-small', $a_node_classes)) $buttonProps['button_size'] = 'small';
                        if (in_array('uk-width-1-1', $a_node_classes)) $buttonProps['full_width'] = true;

                        $elements[] = ["type" => "button", "props" => $buttonProps];
                        $this->log("Converted <a> tag to page builder 'button'. Title: '" . $buttonProps['link_title'] . "', Href: '" . $buttonProps['link_href'] . "'");
                    } else {
                        $this->log("<a> tag with href '" . $node->getAttribute('href') . "' is a regular inline link. It will be preserved by parent's getInnerHTML. Skipping direct element creation.");
                        // No element is created; the link will be part of the parent's innerHTML.
                    }
                    break;

                case 'button':
                    $this->log("Processing button node: " . $this->getInnerHTML($node));
                    $button_node_classes_str = $node->getAttribute('class');
                    $button_node_classes = explode(' ', $button_node_classes_str);
                    
                    $buttonProps = [
                        "button_style" => "default",
                        "link_title" => trim($this->getInnerHTML($node)),
                        "link_href" => '#', // Default for <button> tags as they don't have href
                        "margin" => "default"
                    ];

                    // Map classes similar to <a> buttons
                    if (in_array('uk-button-primary', $button_node_classes)) $buttonProps['button_style'] = 'primary';
                    elseif (in_array('uk-button-secondary', $button_node_classes)) $buttonProps['button_style'] = 'secondary';
                    elseif (in_array('uk-button-danger', $button_node_classes)) $buttonProps['button_style'] = 'danger';
                    elseif (in_array('uk-button-text', $button_node_classes)) $buttonProps['button_style'] = 'text';
                    elseif (in_array('uk-button-link', $button_node_classes)) $buttonProps['button_style'] = 'link';

                    if (in_array('uk-button-large', $button_node_classes)) $buttonProps['button_size'] = 'large';
                    if (in_array('uk-button-small', $button_node_classes)) $buttonProps['button_size'] = 'small';
                    if (in_array('uk-width-1-1', $button_node_classes)) $buttonProps['full_width'] = true;
                    
                    // Consider HTML <button type="submit"> or type="button"
                    // This might not directly map to YOOtheme Pro button element types, but can be logged or stored if needed.
                    // For now, we assume all <button> tags become clickable buttons in page builder.

                    $elements[] = ["type" => "button", "props" => $buttonProps];
                    $this->log("Converted <button> tag to page builder 'button'. Title: '" . $buttonProps['link_title'] . "'");
                    break;
	
				case 'ul':
                    // $nodeContent check might not be relevant if list can be empty but still valid.
                    // For now, keeping it as it was.
					if (!empty($nodeContent)) { 
						$listItems = $node->getElementsByTagName('li');
						$items = [];
						foreach ($listItems as $li) {
							$items[] = [
								"type" => "list_item",
								"props" => ["content" => trim($this->getInnerHTML($li))]
							];
						}
						$listProps = [
							"column_breakpoint" => "m", "image_align" => "left", "image_svg_color" => "emphasis",
							"image_vertical_align" => true, "list_element" => "ul", "list_horizontal_separator" => ", ",
							"list_type" => "vertical", "show_image" => true, "show_link" => true
						];
						$elements[] = ["type" => "list", "props" => $listProps, "children" => $items];
						$this->log("Added UL list element with " . count($items) . " items.");
					} else {
                        $this->log("Skipping empty UL node.", "debug");
                    }
					break;

				case 'ol':
					if (!empty($nodeContent)) {
						$listItems = $node->getElementsByTagName('li');
						$items = [];
						foreach ($listItems as $li) {
							$items[] = [
								"type" => "list_item",
								"props" => ["content" => trim($this->getInnerHTML($li))]
							];
						}
						$listProps = [
							"column_breakpoint" => "m", "image_align" => "left", "image_svg_color" => "emphasis",
							"image_vertical_align" => true, "list_element" => "ol", "list_horizontal_separator" => ", ",
							"list_type" => "vertical", "show_image" => true, "show_link" => true,
							"css" => ".el-element { list-style-type: decimal; padding-left: 20px }"
						];
						$elements[] = ["type" => "list", "props" => $listProps, "children" => $items];
						$this->log("Added OL list element with " . count($items) . " items and custom CSS.");
					} else {
                        $this->log("Skipping empty OL node.", "debug");
                    }
					break;
				
				case 'img':
					$src = $node->getAttribute('src');
					if (!empty($src)) {
                        $imageProps = $this->createImageElementProperties($node); // $node is the <img> tag
                        $elements[] = ["type" => "image", "props" => $imageProps];
					} else {
						$this->log("Skipped image with empty src attribute.", 'warning');
					}
					break;
				case 'table':
                    // $nodeContent check might not be relevant if table can be empty but still valid.
					if (!empty($nodeContent)) { 
						$headers = [];
						$columnCount = 0;
				
						// Try to get column count from headers first
						$headerRow = $node->getElementsByTagName('thead')->item(0);
						$hasHeaders = ($headerRow && $headerRow->getElementsByTagName('th')->length > 0);
						
						if ($hasHeaders) {
							$headerCells = $headerRow->getElementsByTagName('th');
							$columnCount = $headerCells->length;
							foreach ($headerCells as $cell) {
								$headers[] = trim($cell->textContent);
							}
						}
				
						// If no headers, try to get column count from first body row
						if ($columnCount == 0) {
							$tbody = $node->getElementsByTagName('tbody')->item(0);
							if ($tbody) {
								$firstRow = $tbody->getElementsByTagName('tr')->item(0);
								if ($firstRow) {
									$columnCount = $firstRow->getElementsByTagName('td')->length;
								}
							}
						}
				
						$this->log("DEBUG: Final column count: " . $columnCount);
				
						if ($columnCount <= 2) {
							// Use default table element for 2 or fewer columns
							$rows = $node->getElementsByTagName('tr');
							$tableItems = [];
							
							// Process each row to create table_items
							foreach ($rows as $row) {
								$cells = $row->getElementsByTagName('td');
								if ($cells->length >= 2) {
									$tableItems[] = [
										"type" => "table_item",
										"props" => [
											"title" => trim($cells->item(0)->textContent),
											"content" => trim($cells->item(1)->textContent)
										]
									];
								}
							}
				
							$elements[] = [
								"type" => "table",
								"props" => [
									"content" => $nodeContent,
									"image_svg_color" => "emphasis",
									"link_style" => "default",
									"meta_style" => "text-meta",
									"show_content" => true,
									"show_image" => true,
									"show_link" => true,
									"show_meta" => true,
									"show_title" => true,
									"table_order" => "1",
									"table_responsive" => "overflow",
									"table_width_meta" => "shrink",
									"table_width_title" => "shrink"
								],
								"children" => $tableItems
							];
						} else {
							// Use fs_table for more than 2 columns
							$tableItems = [];
							$tbody = $node->getElementsByTagName('tbody')->item(0);
							if ($tbody) {
								$rows = $tbody->getElementsByTagName('tr');
								
								foreach ($rows as $row) {
									$cells = $row->getElementsByTagName('td');
									$itemProps = [
										"label_custom_color" => "#000000",
										"title" => ""
									];
									
									// Map cells to text_1 through text_20
									for ($i = 0; $i < min($columnCount, 20); $i++) {
										$textField = "text_" . ($i + 1);
										$itemProps[$textField] = $cells->item($i) ? trim($cells->item($i)->textContent) : '';
									}
									
									$tableItems[] = [
										"type" => "fs_table_item",
										"props" => $itemProps
									];
								}
							}
				
							// Create fs_table element with base properties
							$tableProps = [
								"show_title" => false,          // Always hide title
								"show_table_title" => false,
								"show_table_content" => false,  // Always hide content
								"show_table_meta" => false,     // Always hide meta
								"table_style" => "divider",
								"table_responsive" => "overflow",
								"table_vertical_align" => true,
								"table_justify" => true,
								"table_order" => "4"
							];
				
							// Enable and set headers for used columns
							for ($i = 1; $i <= 20; $i++) {
								$textField = "text_" . $i;
								$showField = "show_" . $textField;
								$headerField = "table_head_" . $textField;
								
								if ($i <= $columnCount) {
									$tableProps[$showField] = true;
									// Only set header text if headers exist in original table
									if ($hasHeaders) {
										$headerCells = $headerRow->getElementsByTagName('th');
										$tableProps[$headerField] = ($i <= $headerCells->length) ? 
											trim($headerCells->item($i-1)->textContent) : "";
									} else {
										$tableProps[$headerField] = ""; // Empty header if no headers in original
									}
									
									// Set width properties
									$tableProps["table_width_" . $textField] = "shrink";
									$tableProps["table_width_" . $textField . "_custom"] = "100";
								} else {
									$tableProps[$showField] = false;
								}
							}
				
							$elements[] = [
								"type" => "fs_table",
								"props" => $tableProps,
								"children" => $tableItems
							];
						}
						$this->log("Added table element with " . count($tableItems) . " rows and " . $columnCount . " columns");
					}
					break;
	
				case 'blockquote':
					if (!empty($nodeContent)) {
						$elements[] = [
							"type" => "alert",
							"props" => [
								"content" => $nodeContent,
								"style" => "primary"
							]
						];
						$this->log("Added blockquote element");
					}
					break;
					
				default:
                    $nodeContent = trim($this->getInnerHTML($node));
                    // This 'default' is for block-level like elements not handled above.
                    // As per refined plan, these should also append to textBuffer and continue.
                    // However, the buffer flush ALREADY happened.
                    // So, if we append to textBuffer here and continue, it will be part of the *next* text flush.
                    // This makes sense for unknown tags that might appear within a flow of text.
                    $this->log("Default case for element (after pre-flush): " . $nodeNameLower . ". Appending its HTML to text buffer.", "debug");
                    $textBuffer .= $node->ownerDocument->saveHTML($node); // Append its own HTML
                    // No 'continue' here, as it's the end of this iteration's switch.
                    // The content appended here will be flushed either before the next block element or at the end of the loop.
                    break;
			}
		} // End foreach

        // 3. Final Buffer Flush After Loop
        $trimmedTextAtEnd = trim($textBuffer);
        if ($trimmedTextAtEnd !== '') {
            $textElementProps = array_merge(
                ["column_breakpoint" => "m", "margin" => "default", "content" => $trimmedTextAtEnd],
                $this->getTextElementStyleProps($parentNode, 'parent of final buffered text')
            );
            $elements[] = ["type" => "text", "props" => $textElementProps];
            $this->log("Added final coalesced text element, possibly with parent styling: " . substr($trimmedTextAtEnd, 0, 50) . "...");
        }
		
		$this->log("Final element count after coalescing and styling: " . count($elements));
		return $elements;
	}
	
    protected function processImageSrc($src)
	{
		if (empty($src)) {
			return '';
		}
		
		// If it's already a full URL (starts with http or //)
		if (strpos($src, 'http') === 0 || strpos($src, '//') === 0) {
			return $src;
		}
		
		// Handle 'images/' paths and other relative paths
		$src = ltrim($src, '/');
		$siteUrl = \Joomla\CMS\Uri\Uri::root();
		return $siteUrl . $src;
	}
	
	protected function processElementImages(&$element)
	{
        // This method's image-specific responsibilities are now handled by createImageElementProperties
        // for 'image' and 'src' fields at the point of element creation.
        // It no longer needs to process 'image' or 'src' here.
        // $this->log("Reviewing element for image processing (processElementImages): type " . ($element['type'] ?? 'unknown'));

		// if (isset($element['props'])) {
            // 'image' property should hold the original src, so we don't process it here.
			// if (isset($element['props']['image']) && is_string($element['props']['image'])) {
            //    $this->log("processElementImages: Original 'image' property: " . $element['props']['image'] . " - Leaving as is.");
			// }

            // 'src' property is no longer added by createImageElementProperties.
			// if (isset($element['props']['src']) && is_string($element['props']['src'])) {
            //    $this->log("processElementImages: Original 'src' property: " . $element['props']['src'] . " - Leaving as is or ensuring it's correctly set if needed elsewhere.");
			// }
		// }
	
		// Recursively process children - This part should remain if other properties need recursive processing.
		if (isset($element['children']) && is_array($element['children'])) {
			foreach ($element['children'] as &$child) {
				$this->processElementImages($child);
			}
		}
	}

    protected function getInnerHTML($node) 
    {
        $innerHTML = '';
        $children = $node->childNodes;
        foreach ($children as $child) {
            $innerHTML .= $child->ownerDocument->saveHTML($child);
        }
        return $innerHTML;
    }
    
    protected function backupArticle($articleId)
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__content'))
            ->where($this->db->quoteName('id') . ' = ' . (int)$articleId);
            
        $this->db->setQuery($query);
        $backup = $this->db->loadObject();
        
        // Store backup in JSON file
        $backupDir = JPATH_ROOT . '/logs/article_backups';
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        file_put_contents(
            $backupDir . '/article_' . $articleId . '_' . date('Y-m-d_H-i-s') . '.json',
            json_encode($backup, JSON_PRETTY_PRINT)
        );
    }

    protected function log($message, $type = 'info')
    {
        $date = date('Y-m-d H:i:s');
        // Ensure type is a string and sanitize it to prevent injection if it were ever from user input (though it's not here)
        $type = preg_replace('/[^a-zA-Z0-9_-]/', '', $type);
        $logMessage = "[$date][" . strtoupper($type) . "] $message\n";
        
        // Echo to console only if not in test environment or if explicitly enabled
        // For now, let's assume we always echo for CLI scripts.
        // if (php_sapi_name() === 'cli' || (defined('ECHO_LOGS') && ECHO_LOGS)) {
             echo $logMessage;
        // }

        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }
}

// Execute the conversion
try {
    $converter = new ArticleConverter();
    $result = $converter->convert();
    
    if ($result) {
        echo "Conversion completed successfully. Check the logs for details.\n";
    } else {
        echo "Conversion completed with errors. Check the logs for details.\n";
    }
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
}
