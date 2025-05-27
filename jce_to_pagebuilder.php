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
        $elements = $this->parseContentIntoElements($article->introtext);
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
			$query = $this->db->getQuery(true)
				->update($this->db->quoteName('#__content'))
				->set($this->db->quoteName('introtext') . ' = ' . $this->db->quote(''))
				->where($this->db->quoteName('id') . ' = ' . (int)$article->id);
				
			$this->db->setQuery($query);
			$this->db->execute();
	
			$this->log("Successfully updated article ID {$article->id}");
			return true;
		} catch (Exception $e) {
			$this->log("Error updating article ID {$article->id}: " . $e->getMessage(), 'error');
			return false;
		}
	}


    protected function parseContentIntoElements($content)
	{
		$this->log("Starting content parsing");
		
		$elements = [];
		
		// Ensure we have a proper HTML structure
		$wrappedContent = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $content . '</body></html>';
		
		// Split content by significant HTML elements
		$dom = new DOMDocument('1.0', 'UTF-8');
		libxml_use_internal_errors(true);
		
		// Load the HTML and preserve white space
		$dom->preserveWhiteSpace = true;
		$dom->loadHTML($wrappedContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		
		// Clear any XML errors
		$errors = libxml_get_errors();
		libxml_clear_errors();
		
		// Get the body content
		$body = $dom->getElementsByTagName('body')->item(0);
		
		if (!$body) {
			$this->log("No body tag found!", 'error');
			return $elements;
		}
		
		// Process each child node of the body
		foreach ($body->childNodes as $node) {
			if ($node->nodeType !== XML_ELEMENT_NODE) {
				continue;
			}
			
			$nodeContent = trim($this->getInnerHTML($node));
			$textContent = trim($node->textContent);
			
			// Skip if both innerHTML and textContent are empty
			if (empty($nodeContent) && empty($textContent)) {
				$this->log("Skipping empty node: " . $node->nodeName);
				continue;
			}
			
			switch (strtolower($node->nodeName)) {
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
					// Add debug logging
					$this->log("Processing div node");
					$children = $node->childNodes;
					$this->log("Div has " . $children->length . " children");
				
					// Check if div contains only an image
					if ($children->length === 1 && $children->item(0)->nodeName === 'img') {
						$this->log("Found single image in div - processing as image element");
						$imgNode = $children->item(0);
						$src = $imgNode->getAttribute('src');
						
						if (!empty($src)) {
							$originalSrc = $src;
							
							// Handle relative URLs for src
							if (strpos($src, 'http') !== 0 && strpos($src, '//') !== 0) {
								$src = ltrim($src, '/');
								$siteUrl = \Joomla\CMS\Uri\Uri::root();
								$src = $siteUrl . $src;
							}
				
							$width = $imgNode->getAttribute('width') ?: '';
							$height = $imgNode->getAttribute('height') ?: '';
							
							$elements[] = [
								"type" => "image",
								"props" => [
									"image_svg_color" => "emphasis",
									"margin" => "default",
									"image" => $originalSrc,
									"image_width" => intval($width),
									"image_height" => intval($height),
									"image_size" => "auto",
									"src" => $src,
									"alt" => $imgNode->getAttribute('alt') ?: '',
									"title" => $imgNode->getAttribute('title') ?: '',
									"width" => $width,
									"height" => $height,
									"uk_img" => true,
									"position" => "none"
								]
							];
							$this->log("Added image element: " . $src);
						}
					} else {
						// Handle as regular div content
						$content = $this->getInnerHtml($node);
						if (trim($content) !== '') {
							$elements[] = [
								"type" => "text",
								"props" => [
									"column_breakpoint" => "m",
									"margin" => "default",
									"content" => $content
								]
							];
						}
					}
					break;
    
    			case 'p':
					// Check if paragraph contains only an image
					$children = $node->childNodes;
					if ($children->length === 1 && $children->item(0)->nodeName === 'img') {
						// Handle the image directly
						$imgNode = $children->item(0);
						$src = $imgNode->getAttribute('src');
						if (!empty($src)) {
							$originalSrc = $src;
							
							// Get style attribute and parse it
							$style = $imgNode->getAttribute('style');
							$alignment = 'none';
							$marginRight = '';
							$marginLeft = '';
							
							if (strpos($style, 'float: right') !== false) {
								$alignment = 'right';
								if (strpos($style, 'margin-right') !== false) {
									$marginRight = 'medium';
								}
							} elseif (strpos($style, 'float: left') !== false) {
								$alignment = 'left';
								if (strpos($style, 'margin-left') !== false) {
									$marginLeft = 'medium';
								}
							}
				
							// Handle relative URLs for src
							if (strpos($src, 'http') !== 0 && strpos($src, '//') !== 0) {
								$src = ltrim($src, '/');
								$siteUrl = \Joomla\CMS\Uri\Uri::root();
								$src = $siteUrl . $src;
							}
				
							$width = $imgNode->getAttribute('width') ?: '';
							$height = $imgNode->getAttribute('height') ?: '';
							
							$elements[] = [
								"type" => "image",
								"props" => [
									"image_svg_color" => "emphasis",
									"margin" => "default",
									"image" => $originalSrc,
									"image_width" => intval($width),
									"image_height" => intval($height),
									"image_size" => "auto",
									"src" => $src,
									"alt" => $imgNode->getAttribute('alt') ?: '',
									"title" => $imgNode->getAttribute('title') ?: '',
									"width" => $width,
									"height" => $height,
									"uk_img" => true,
									"position" => $alignment,
									"margin_right" => $marginRight,
									"margin_left" => $marginLeft
								]
							];
							$this->log("Added image element: " . $src);
						}
					} else {
						// Handle as regular paragraph
						$content = $this->getInnerHtml($node);
						if (trim($content) !== '') {
							$elements[] = [
								"type" => "text",
								"props" => [
									"column_breakpoint" => "m",
									"margin" => "default",
									"content" => $content
								]
							];
						}
					}
					break;
	
				case 'ul':
				case 'ol':
					if (!empty($nodeContent)) {
						// Get all list items
						$listItems = $node->getElementsByTagName('li');
						$items = [];
						
						// Create list_item elements for each li
						foreach ($listItems as $li) {
							$items[] = [
								"type" => "list_item",
								"props" => [
									"content" => trim($li->textContent)
								]
							];
						}
				
						$elements[] = [
							"type" => "list",
							"props" => [
								"column_breakpoint" => "m",
								"image_align" => "left",
								"image_svg_color" => "emphasis",
								"image_vertical_align" => true,
								"list_element" => $node->nodeName, // "ul" or "ol"
								"list_horizontal_separator" => ", ",
								"list_type" => "vertical",
								"show_image" => true,
								"show_link" => true
							],
							"children" => $items
						];
						$this->log("Added {$node->nodeName} list element with " . count($items) . " items");
					}
					break;
				
				case 'img':
					$src = $node->getAttribute('src');
					if (!empty($src)) {
						// Store original src for 'image' property
						$originalSrc = $src;
						
						// Get style attribute and parse it
						$style = $node->getAttribute('style');
						$alignment = 'none';
						$marginRight = '';
						$marginLeft = '';
						
						if (strpos($style, 'float: right') !== false) {
							$alignment = 'right';
							if (strpos($style, 'margin-right') !== false) {
								$marginRight = 'medium';
							}
						} elseif (strpos($style, 'float: left') !== false) {
							$alignment = 'left';
							if (strpos($style, 'margin-left') !== false) {
								$marginLeft = 'medium';
							}
						}
				
						// Get width and height
						$width = $node->getAttribute('width') ?: '';
						$height = $node->getAttribute('height') ?: '';
						
						// Handle relative URLs for src
						if (strpos($src, 'http') !== 0 && strpos($src, '//') !== 0) {
							$src = ltrim($src, '/');
							$siteUrl = \Joomla\CMS\Uri\Uri::root();
							$src = $siteUrl . $src;
						}
						
						$elements[] = [
							"type" => "image",
							"props" => [
								"image_svg_color" => "emphasis",
								"margin" => "default",
								"image" => $originalSrc,  // Original relative path
								"image_width" => intval($width),  // Integer width
								"image_height" => intval($height), // Integer height
								"image_size" => "auto",
								"src" => $src,  // Full URL
								"alt" => $node->getAttribute('alt') ?: '',
								"title" => $node->getAttribute('title') ?: '',
								"width" => $width,  // Original width string
								"height" => $height, // Original height string
								"uk_img" => true,
								"position" => $alignment,
								"margin_right" => $marginRight,
								"margin_left" => $marginLeft
							]
						];
						$this->log("Added image element: " . $src);
					} else {
						$this->log("Skipped image with empty src attribute", 'warning');
					}
					break;
				case 'table':
					if (!empty($nodeContent)) {
						// Get table headers and count columns
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
					// For any other HTML elements, wrap them in a text element
					if (!empty($nodeContent)) {
						$elements[] = [
							"type" => "text",
							"props" => [
								"column_breakpoint" => "m",
								"margin" => "default",
								"content" => $nodeContent
							]
						];
						$this->log("Added generic text element for " . $node->nodeName);
					}
					break;
			}
		}
		
		$this->log("Final element count: " . count($elements));
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
		// Process image properties in the current element
		if (isset($element['props'])) {
			if (isset($element['props']['image'])) {
				$element['props']['image'] = $this->processImageSrc($element['props']['image']);
			}
			if (isset($element['props']['src'])) {
				$element['props']['src'] = $this->processImageSrc($element['props']['src']);
			}
		}
	
		// Recursively process children
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
        $logMessage = "[$date][$type] $message\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        echo $logMessage;
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
