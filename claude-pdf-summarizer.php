<?php
/**
 * Plugin Name: Sumario Ejecutivo con IA
 * Description: Gutenberg block for uploading PDFs and generating AI summaries with Claude
 * Version: 1.2.0
 * Author: Your Name
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include Composer autoloader if it exists
if (file_exists(plugin_dir_path(__FILE__) . 'vendor/autoload.php')) {
  require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
}


class PDFSummarizerBlock {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_summarize_pdf_block', array($this, 'ajax_summarize_pdf'));
        add_action('wp_ajax_nopriv_summarize_pdf_block', array($this, 'ajax_summarize_pdf'));
        add_action('admin_notices', array($this, 'check_dependencies'));
    }
    
    public function init() {
        // Register the block
        register_block_type('pdf-summarizer/pdf-block', array(
            'editor_script' => 'pdf-summarizer-block-editor',
            'editor_style' => 'pdf-summarizer-block-editor-style',
            'style' => 'pdf-summarizer-block-style',
            'render_callback' => array($this, 'render_block'),
            'attributes' => array(
                'pdfUrl' => array(
                    'type' => 'string',
                    'default' => ''
                ),
                'summary' => array(
                    'type' => 'string',
                    'default' => ''
                ),
                'fileName' => array(
                    'type' => 'string',
                    'default' => ''
                ),
                'showSummary' => array(
                    'type' => 'boolean',
                    'default' => true
                ),
                'summaryTitle' => array(
                    'type' => 'string',
                    'default' => 'Document Summary'
                ),
                'extractedText' => array(
                    'type' => 'string',
                    'default' => ''
                ),
                'wordCount' => array(
                    'type' => 'number',
                    'default' => 0
                ),
                'isEditing' => array(
                    'type' => 'boolean',
                    'default' => false
                ),
                'tempSummary' => array(
                    'type' => 'string',
                    'default' => ''
                )
            )
        ));
        
        // Enqueue block assets
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_block_editor_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_block_assets'));
    }
    
    public function check_dependencies() {
        if (!class_exists('Smalot\PdfParser\Parser')) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>Resumen de PDF con AI:</strong> Para el correcto funcionamiento instala la herramienta de extracción de texto: ';
            echo '<code>composer require smalot/pdfparser</code> en el directorio del plugin.';
            echo '</p></div>';
        }
    }
    
    public function enqueue_block_editor_assets() {
        wp_enqueue_script(
            'pdf-summarizer-block-editor',
            plugin_dir_url(__FILE__) . 'block.js',
            array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n'),
            '1.2.0'
        );
        
        wp_enqueue_style(
            'pdf-summarizer-block-editor-style',
            plugin_dir_url(__FILE__) . 'editor.css',
            array('wp-edit-blocks'),
            '1.2.0'
        );
        
        wp_localize_script('pdf-summarizer-block-editor', 'pdfSummarizerAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pdf_summarizer_nonce')
        ));
    }
    
    public function enqueue_block_assets() {
        wp_enqueue_style(
            'pdf-summarizer-block-style',
            plugin_dir_url(__FILE__) . 'style.css',
            array(),
            '1.2.0'
        );
    }
    
    public function render_block($attributes) {
        $pdf_url = $attributes['pdfUrl'] ?? '';
        $summary = $attributes['summary'] ?? '';
        $file_name = $attributes['fileName'] ?? '';
        $show_summary = $attributes['showSummary'] ?? true;
        $summary_title = $attributes['summaryTitle'] ?? 'Resumen';
        $word_count = $attributes['wordCount'] ?? 0;
        
        if (empty($pdf_url) && empty($summary)) {
            return '<div class="pdf-summarizer-placeholder">Resumen de PDF</div>';
        }
        
        ob_start();
        ?>
        <div class="pdf-summarizer-block">
            <!-- <?php if ($pdf_url): ?>
                <div class="pdf-download">
                    <a href="<?php echo esc_url($pdf_url); ?>" target="_blank" class="pdf-link">
                        📄 <?php echo esc_html($file_name ?: 'Download PDF'); ?>
                    </a>
                    <?php if ($word_count > 0): ?>
                        <span class="word-count">(<?php echo number_format($word_count); ?> words extracted)</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?> -->
            
            <?php if ($show_summary && $summary): ?>
                <div class="pdf-summary">
                    <h3 class="summary-title"><?php echo esc_html($summary_title); ?></h3>
                    <div class="summary-content">
                        <?php echo wp_kses_post(wpautop($summary)); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function add_admin_menu() {
        add_options_page(
            'Configuraciones de Resumen de PDF con AI',
            'Resumen de PDF con AI',
            'manage_options',
            'pdf-summarizer-settings',
            array($this, 'settings_page')
        );
    }
    
    public function settings_page() {
        if (isset($_POST['submit'])) {
            update_option('pdf_summarizer_api_key', sanitize_text_field($_POST['api_key']));
            update_option('pdf_summarizer_prompt', wp_kses_post($_POST['custom_prompt']));
            update_option('pdf_summarizer_max_tokens', intval($_POST['max_tokens']));
            update_option('pdf_summarizer_text_limit', intval($_POST['text_limit']));
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        $api_key = get_option('pdf_summarizer_api_key', '');
        $custom_prompt = get_option('pdf_summarizer_prompt', 'Genera un resumen de este documento PDF, destacando los puntos clave y las conclusiones principales, sin incluir información irrelevante o redundante.');
        $max_tokens = get_option('pdf_summarizer_max_tokens', 1500);
        $text_limit = get_option('pdf_summarizer_text_limit', 15000);
        ?>
        <div class="wrap">
            <h1>Configuraciones de Resumen de PDF con AI</h1>
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row">Claude API Key</th>
                        <td>
                            <input type="password" name="api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
                            <p class="description">Enter your Anthropic Claude API key</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Prompt por defecto</th>
                        <td>
                            <textarea name="custom_prompt" rows="5" cols="50" class="large-text"><?php echo esc_textarea($custom_prompt); ?></textarea>
                            <p class="description">Instrucciones que se le darán a la IA sobre como resumir los documentos</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Máxima cantidad de tokens a utilizar</th>
                        <td>
                            <input type="number" name="max_tokens" value="<?php echo esc_attr($max_tokens); ?>" min="100" max="4000" />
                            <p class="description">Maximum tokens for Claude response (100-4000)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Límite de extracción de texto</th>
                        <td>
                            <input type="number" name="text_limit" value="<?php echo esc_attr($text_limit); ?>" min="1000" max="50000" />
                            <p class="description">Maximum characters to extract from PDF (1000-50000)</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <h2>Text Extraction Methods</h2>
            <p>This plugin uses multiple methods to extract text from PDFs:</p>
            <ul>
                <li><strong>PDF Parser Library:</strong> <?php echo class_exists('Smalot\PdfParser\Parser') ? '✅ Available' : '❌ Not installed'; ?></li>
                <li><strong>pdftotext (shell):</strong> <?php echo $this->check_pdftotext() ? '✅ Available' : '❌ Not available'; ?></li>
                <li><strong>Basic extraction:</strong> ✅ Always available (fallback)</li>
            </ul>
        </div>
        <?php
    }
    
    public function ajax_summarize_pdf() {
        if (!wp_verify_nonce($_POST['nonce'], 'pdf_summarizer_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('File upload failed: ' . $_FILES['pdf_file']['error']);
        }
        
        // Handle file upload
        $uploaded_file = wp_handle_upload($_FILES['pdf_file'], array('test_form' => false));
        
        if (isset($uploaded_file['error'])) {
            wp_send_json_error($uploaded_file['error']);
        }
        
        // Extract text from PDF
        $extracted_text = $this->extract_pdf_text($uploaded_file['file']);
        if (!$extracted_text) {
            wp_send_json_error('Failed to extract text from PDF. The PDF might be image-based or corrupted.');
        }
        
        // Get word count
        $word_count = str_word_count($extracted_text);
        
        // Limit text length for API
        $text_limit = get_option('pdf_summarizer_text_limit', 15000);
        if (strlen($extracted_text) > $text_limit) {
            $extracted_text = substr($extracted_text, 0, $text_limit) . '...';
        }
        
        // Get custom prompt if provided
        $custom_prompt = isset($_POST['custom_prompt']) ? sanitize_textarea_field($_POST['custom_prompt']) : '';
        
        // Send text to Claude for summarization
        $summary = $this->get_claude_summary_from_text($extracted_text, $custom_prompt);
        if (!$summary) {
            wp_send_json_error('Failed to generate summary. Please check your API key and try again.');
        }
        
        wp_send_json_success(array(
            'summary' => $summary,
            'pdf_url' => $uploaded_file['url'],
            'file_name' => $_FILES['pdf_file']['name'],
            'extracted_text' => $extracted_text,
            'word_count' => $word_count
        ));
    }
    
    private function extract_pdf_text($pdf_path) {
        $text = '';
        
        // Method 1: Try PDF Parser library (if available)
        if (class_exists('Smalot\PdfParser\Parser')) {
          error_log('Using PDF Parser library for text extraction');
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($pdf_path);
                $text = $pdf->getText();
                if (trim($text)) {
                    return $this->clean_extracted_text($text);
                }
            } catch (Exception $e) {
                error_log('PDF Parser error: ' . $e->getMessage());
            }
        }
        
        // Method 2: Try pdftotext command line tool
        if ($this->check_pdftotext()) {
            $temp_file = tempnam(sys_get_temp_dir(), 'pdf_text');
            $command = sprintf('pdftotext %s %s 2>/dev/null', escapeshellarg($pdf_path), escapeshellarg($temp_file));
            exec($command, $output, $return_code);
            
            if ($return_code === 0 && file_exists($temp_file)) {
                $text = file_get_contents($temp_file);
                unlink($temp_file);
                if (trim($text)) {
                    return $this->clean_extracted_text($text);
                }
            }
        }
        
        // Method 3: Basic PDF text extraction (fallback)
        $text = $this->basic_pdf_text_extraction($pdf_path);
        if (trim($text)) {
            return $this->clean_extracted_text($text);
        }
        
        return false;
    }
    
    private function basic_pdf_text_extraction($pdf_path) {
        $content = file_get_contents($pdf_path);
        if (!$content) {
            return false;
        }
        
        // Very basic PDF text extraction - looks for text between stream markers
        $text = '';
        if (preg_match_all('/stream\s*\n(.*?)\nendstream/s', $content, $matches)) {
            foreach ($matches[1] as $match) {
                // Try to extract readable text
                $decoded = @gzuncompress($match);
                if ($decoded === false) {
                    $decoded = $match;
                }
                
                // Extract text-like content
                if (preg_match_all('/\((.*?)\)/', $decoded, $text_matches)) {
                    foreach ($text_matches[1] as $text_match) {
                        $text .= $text_match . ' ';
                    }
                }
            }
        }
        
        return $text;
    }
    
    private function clean_extracted_text($text) {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Remove non-printable characters except basic punctuation
        $text = preg_replace('/[^\p{L}\p{N}\p{P}\p{Z}]/u', '', $text);
        
        // Trim and return
        return trim($text);
    }
    
    private function check_pdftotext() {
        exec('which pdftotext', $output, $return_code);
        return $return_code === 0;
    }
    
    private function get_claude_summary_from_text($text, $custom_prompt = '') {
        $api_key = get_option('pdf_summarizer_api_key');
        if (!$api_key) {
            return false;
        }
        
        if (empty($custom_prompt)) {
            $custom_prompt = get_option('pdf_summarizer_prompt', 'Please provide a concise summary of this document, highlighting the key points and main conclusions.');
        }
        
        $max_tokens = get_option('pdf_summarizer_max_tokens', 1500);
        
        $data = array(
            'model' => 'claude-3-5-sonnet-20241022',
            'max_tokens' => intval($max_tokens),
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $custom_prompt . "\n\nDocument text:\n" . $text
                )
            )
        );
        
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01'
            ),
            'body' => json_encode($data),
            'timeout' => 90
        ));
        
        if (is_wp_error($response)) {
            error_log('Claude API Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (isset($result['content'][0]['text'])) {
            return $result['content'][0]['text'];
        }
        
        if (isset($result['error'])) {
            error_log('Claude API Error: ' . print_r($result['error'], true));
        }
        
        return false;
    }
}

// Initialize the plugin
new PDFSummarizerBlock();

// Create the JavaScript file for the Gutenberg block
add_action('init', function() {
    $block_js = "
(function() {
    const { registerBlockType } = wp.blocks;
    const { createElement: el, useState, useEffect } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, TextControl, ToggleControl, TextareaControl, Button, Spinner } = wp.components;
    const { __ } = wp.i18n;

    registerBlockType('pdf-summarizer/pdf-block', {
        title: __('Sumario Ejecutivo'),
        icon: 'media-document',
        category: 'media',
        
        edit: function(props) {
            const { attributes, setAttributes } = props;
            const { pdfUrl, summary, fileName, showSummary, summaryTitle, extractedText, wordCount, isEditing, tempSummary } = attributes;
            const [isUploading, setIsUploading] = useState(false);
            const [customPrompt, setCustomPrompt] = useState('');
            const [showExtractedText, setShowExtractedText] = useState(true);
            const blockProps = useBlockProps();

            const uploadPDF = function(file) {
                if (!file || file.type !== 'application/pdf') {
                    alert('Please select a PDF file.');
                    return;
                }

                setIsUploading(true);
                
                const formData = new FormData();
                formData.append('action', 'summarize_pdf_block');
                formData.append('pdf_file', file);
                formData.append('nonce', pdfSummarizerAjax.nonce);
                if (customPrompt) {
                    formData.append('custom_prompt', customPrompt);
                }

                fetch(pdfSummarizerAjax.ajaxurl, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    setIsUploading(false);
                    if (data.success) {
                        setAttributes({
                            pdfUrl: data.data.pdf_url,
                            summary: data.data.summary,
                            fileName: data.data.file_name,
                            extractedText: data.data.extracted_text,
                            wordCount: data.data.word_count,
                            isEditing: true,
                            tempSummary: data.data.summary
                        });
                    } else {
                        alert('Error: ' + data.data);
                    }
                })
                .catch(error => {
                    setIsUploading(false);
                    alert('Upload failed: ' + error.message);
                });
            };

            const saveSummary = function() {
                setAttributes({
                    summary: tempSummary,
                    isEditing: false
                });
            };

            const cancelEdit = function() {
                setAttributes({
                    tempSummary: summary,
                    isEditing: false
                });
            };

            const editSummary = function() {
                setAttributes({
                    tempSummary: summary,
                    isEditing: true
                });
            };

            return el('div', blockProps, [
                el(InspectorControls, { key: 'inspector' }, [
                    el(PanelBody, { title: __('Block Settings'), key: 'settings' }, [])
                ]),
                
                el('div', { className: 'pdf-summarizer-editor', key: 'content' }, [
                    !pdfUrl && el('div', { className: 'upload-area' }, [
                        el('h3', {}, __('Sumario Ejecutivo
                        el('p', {}, __('Adjunta un archivo PDF (original, no escaneado) para extraer texto y generar un resumen con IA.')),
                            type: 'file',
                            accept: '.pdf',
                            onChange: (e) => uploadPDF(e.target.files[0]),
                            disabled: isUploading
                        }),
                        isUploading && el('div', { style: { marginTop: '10px' } }, [
                            el(Spinner, {}),
                            el('span', { style: { marginLeft: '10px' } }, __('Extracting text and generating summary...'))
                        ])
                    ]),
                    
                    pdfUrl && el('div', { className: 'pdf-block-preview' }, [
                        el('div', { className: 'pdf-info' }, [
                            el('strong', {}, __('PDF: ')),
                            el('a', { href: pdfUrl, target: '_blank' }, fileName || __('View PDF')),
                            wordCount > 0 && el('span', { 
                                style: { marginLeft: '10px', color: '#666', fontSize: '0.9em' }
                            }, '(' + wordCount.toLocaleString() + ' words extracted)'),
                            el(Button, {
                                isSmall: true,
                                isDestructive: true,
                                onClick: () => setAttributes({ 
                                    pdfUrl: '', 
                                    summary: '', 
                                    fileName: '', 
                                    extractedText: '', 
                                    wordCount: 0,
                                    isEditing: false,
                                    tempSummary: ''
                                }),
                                style: { marginLeft: '10px' }
                            }, __('Remove'))
                        ]),
                        
                        showExtractedText && extractedText && el('div', { className: 'extracted-text-preview' }, [
                            el('h4', {}, __('Extracted Text')),
                            el('div', { 
                                style: { 
                                    background: '#fafafa', 
                                    padding: '10px', 
                                    borderRadius: '4px',
                                    maxHeight: '200px',
                                    overflow: 'auto',
                                    fontSize: '0.9em',
                                    whiteSpace: 'pre-wrap'
                                } 
                            }, extractedText.substring(0, 1000) + (extractedText.length > 1000 ? '...' : ''))
                        ]),
                        
                        showSummary && el('div', { className: 'summary-section' }, [
                            el('h4', {}, summaryTitle),
                            
                            // Show textarea when editing
                            isEditing && el('div', { className: 'summary-edit-mode' }, [
                                el('textarea', {
                                    value: tempSummary,
                                    onChange: (e) => setAttributes({ tempSummary: e.target.value }),
                                    rows: 10,
                                    style: {
                                        width: '100%',
                                        padding: '10px',
                                        borderRadius: '4px',
                                        border: '1px solid #ddd',
                                        fontSize: '16px',
                                        fontFamily: 'inherit',
                                        lineHeight: '1.5'
                                    }
                                }),
                                el('div', { style: { marginTop: '10px' } }, [
                                    el(Button, {
                                        isPrimary: true,
                                        onClick: saveSummary,
                                        style: { marginRight: '10px' }
                                    }, __('Guardar')),
                                    el(Button, {
                                        isSecondary: true,
                                        onClick: cancelEdit
                                    }, __('Cancelar'))
                                ])
                            ]),
                            
                            // Show formatted summary when not editing
                            !isEditing && summary && el('div', { className: 'summary-display-mode' }, [
                                el('div', { 
                                    style: { 
                                        background: 'transparent', 
                                        whiteSpace: 'pre-wrap',
                                        lineHeight: '1.6',
                                    } 
                                }, summary),
                                el('div', { style: { marginTop: '10px' } }, [
                                    el(Button, {
                                        isSecondary: true,
                                        onClick: editSummary
                                    }, __('Editar'))
                                ])
                            ])
                        ])
                    ])
                ])
            ]);
        },

        save: function() {
            return null; // Server-side rendering
        }
    });
})();
";

    if (!file_exists(plugin_dir_path(__FILE__) . 'block.js')) {
        file_put_contents(plugin_dir_path(__FILE__) . 'block.js', $block_js);
    }
    
    // Create updated CSS files
    $editor_css = "
.pdf-summarizer-editor {
    border: 1px solid #ddd;
    padding: 20px;
    text-align: center;
    border-radius: 8px;
}

.upload-area input[type='file'] {
    margin: 10px 0;
}

.pdf-block-preview {
    text-align: left;
}

.pdf-info {
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.summary-section {
    margin-top: 15px;
}

.summary-edit-mode textarea {
    resize: vertical;
    min-height: 150px;
}

.summary-display-mode {
    margin-top: 10px;
}

.extracted-text-preview {
    margin-top: 15px;
    margin-bottom: 15px;
}

.extracted-text-preview h4 {
    margin-bottom: 8px;
    color: #333;
}

.summary-section h4 {
    margin-bottom: 10px;
    color: #333;
}
";

    $style_css = "
.pdf-summarizer-block {
    margin: 20px 0;
    padding: 20px;
    background: transparent;
}

.pdf-download {
    margin-bottom: 15px;
}

.pdf-link {
    display: inline-block;
    padding: 10px 15px;
    background: #0073aa;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    font-weight: bold;
}

.pdf-link:hover {
    background: #005a87;
    color: white;
}

.word-count {
    margin-left: 10px;
    color: #666;
    font-size: 0.9em;
}

.pdf-summary {
    margin-top: 15px;
}

.summary-title {
    margin: 0 0 10px 0;
    color: #333;
}

.summary-content {
    line-height: 1.6;
    color: #555;
}

.pdf-summarizer-placeholder {
    padding: 20px;
    text-align: center;
}
";

    if (!file_exists(plugin_dir_path(__FILE__) . 'editor.css')) {
        file_put_contents(plugin_dir_path(__FILE__) . 'editor.css', $editor_css);
    }
    
    if (!file_exists(plugin_dir_path(__FILE__) . 'style.css')) {
        file_put_contents(plugin_dir_path(__FILE__) . 'style.css', $style_css);
    }
});
?>