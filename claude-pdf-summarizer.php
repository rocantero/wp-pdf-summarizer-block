<?php
/**
 * Plugin Name: PDF Summarizer Block
 * Description: Gutenberg block for uploading PDFs and generating AI summaries with Claude
 * Version: 1.0.0
 * Author: Your Name
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PDFSummarizerBlock {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_summarize_pdf_block', array($this, 'ajax_summarize_pdf'));
        add_action('wp_ajax_nopriv_summarize_pdf_block', array($this, 'ajax_summarize_pdf'));
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
                )
            )
        ));
        
        // Enqueue block assets
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_block_editor_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_block_assets'));
    }
    
    public function enqueue_block_editor_assets() {
        wp_enqueue_script(
            'pdf-summarizer-block-editor',
            plugin_dir_url(__FILE__) . 'block.js',
            array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n'),
            '1.0.0'
        );
        
        wp_enqueue_style(
            'pdf-summarizer-block-editor-style',
            plugin_dir_url(__FILE__) . 'editor.css',
            array('wp-edit-blocks'),
            '1.0.0'
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
            '1.0.0'
        );
    }
    
    public function render_block($attributes) {
        $pdf_url = $attributes['pdfUrl'] ?? '';
        $summary = $attributes['summary'] ?? '';
        $file_name = $attributes['fileName'] ?? '';
        $show_summary = $attributes['showSummary'] ?? true;
        $summary_title = $attributes['summaryTitle'] ?? 'Document Summary';
        
        if (empty($pdf_url) && empty($summary)) {
            return '<div class="pdf-summarizer-placeholder">PDF Summarizer Block - Configure in editor</div>';
        }
        
        ob_start();
        ?>
        <div class="pdf-summarizer-block">
            <?php if ($pdf_url): ?>
                <div class="pdf-download">
                    <a href="<?php echo esc_url($pdf_url); ?>" target="_blank" class="pdf-link">
                        📄 <?php echo esc_html($file_name ?: 'Download PDF'); ?>
                    </a>
                </div>
            <?php endif; ?>
            
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
            'PDF Summarizer Settings',
            'PDF Summarizer',
            'manage_options',
            'pdf-summarizer-settings',
            array($this, 'settings_page')
        );
    }
    
    public function settings_page() {
        if (isset($_POST['submit'])) {
            update_option('pdf_summarizer_api_key', sanitize_text_field($_POST['api_key']));
            update_option('pdf_summarizer_prompt', wp_kses_post($_POST['custom_prompt']));
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        $api_key = get_option('pdf_summarizer_api_key', '');
        $custom_prompt = get_option('pdf_summarizer_prompt', 'Please provide a concise summary of this PDF document, highlighting the key points and main conclusions.');
        ?>
        <div class="wrap">
            <h1>PDF Summarizer Settings</h1>
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
                        <th scope="row">Default Summarization Prompt</th>
                        <td>
                            <textarea name="custom_prompt" rows="5" cols="50" class="large-text"><?php echo esc_textarea($custom_prompt); ?></textarea>
                            <p class="description">Default prompt for PDF summarization (can be customized per block)</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
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
        
        // Get custom prompt if provided
        $custom_prompt = isset($_POST['custom_prompt']) ? sanitize_textarea_field($_POST['custom_prompt']) : '';
        
        // Send PDF to Claude for summarization
        $summary = $this->get_claude_summary_from_pdf($uploaded_file['file'], $custom_prompt);
        if (!$summary) {
            wp_send_json_error('Failed to generate summary. Please check your API key and try again.');
        }
        
        wp_send_json_success(array(
            'summary' => $summary,
            'pdf_url' => $uploaded_file['url'],
            'file_name' => $_FILES['pdf_file']['name']
        ));
    }
    
    private function get_claude_summary_from_pdf($pdf_path, $custom_prompt = '') {
        $api_key = get_option('pdf_summarizer_api_key');
        if (!$api_key) {
            return false;
        }
        
        if (empty($custom_prompt)) {
            $custom_prompt = get_option('pdf_summarizer_prompt', 'Please provide a concise summary of this PDF document, highlighting the key points and main conclusions.');
        }
        
        // Read PDF file and encode it
        $pdf_content = file_get_contents($pdf_path);
        if (!$pdf_content) {
            return false;
        }
        
        $pdf_base64 = base64_encode($pdf_content);
        
        $data = array(
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 1500,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'document',
                            'source' => array(
                                'type' => 'base64',
                                'media_type' => 'application/pdf',
                                'data' => $pdf_base64
                            )
                        ),
                        array(
                            'type' => 'text',
                            'text' => $custom_prompt
                        )
                    )
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
        title: __('PDF Summarizer'),
        icon: 'media-document',
        category: 'media',
        
        edit: function(props) {
            const { attributes, setAttributes } = props;
            const { pdfUrl, summary, fileName, showSummary, summaryTitle } = attributes;
            const [isUploading, setIsUploading] = useState(false);
            const [customPrompt, setCustomPrompt] = useState('');
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
                            fileName: data.data.file_name
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

            return el('div', blockProps, [
                el(InspectorControls, { key: 'inspector' }, [
                    el(PanelBody, { title: __('Block Settings'), key: 'settings' }, [
                        el(ToggleControl, {
                            label: __('Show Summary'),
                            checked: showSummary,
                            onChange: (value) => setAttributes({ showSummary: value })
                        }),
                        el(TextControl, {
                            label: __('Summary Title'),
                            value: summaryTitle,
                            onChange: (value) => setAttributes({ summaryTitle: value })
                        }),
                        el(TextareaControl, {
                            label: __('Custom Prompt (optional)'),
                            value: customPrompt,
                            onChange: setCustomPrompt,
                            placeholder: __('Leave empty to use default prompt from settings')
                        })
                    ])
                ]),
                
                el('div', { className: 'pdf-summarizer-editor', key: 'content' }, [
                    !pdfUrl && el('div', { className: 'upload-area' }, [
                        el('h3', {}, __('PDF Summarizer Block')),
                        el('input', {
                            type: 'file',
                            accept: '.pdf',
                            onChange: (e) => uploadPDF(e.target.files[0]),
                            disabled: isUploading
                        }),
                        isUploading && el('div', { style: { marginTop: '10px' } }, [
                            el(Spinner, {}),
                            el('span', { style: { marginLeft: '10px' } }, __('Processing PDF...'))
                        ])
                    ]),
                    
                    pdfUrl && el('div', { className: 'pdf-block-preview' }, [
                        el('div', { className: 'pdf-info' }, [
                            el('strong', {}, __('PDF: ')),
                            el('a', { href: pdfUrl, target: '_blank' }, fileName || __('View PDF')),
                            el(Button, {
                                isSmall: true,
                                isDestructive: true,
                                onClick: () => setAttributes({ pdfUrl: '', summary: '', fileName: '' }),
                                style: { marginLeft: '10px' }
                            }, __('Remove'))
                        ]),
                        
                        showSummary && summary && el('div', { className: 'summary-preview' }, [
                            el('h4', {}, summaryTitle),
                            el('div', { 
                                style: { 
                                    background: '#f0f0f0', 
                                    padding: '15px', 
                                    borderRadius: '4px',
                                    whiteSpace: 'pre-wrap'
                                } 
                            }, summary)
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
    
    // Create basic CSS files
    $editor_css = "
.pdf-summarizer-editor {
    border: 2px dashed #ddd;
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

.summary-preview {
    margin-top: 15px;
}
";

    $style_css = "
.pdf-summarizer-block {
    margin: 20px 0;
    padding: 20px;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    background: #fafafa;
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
    color: #666;
    font-style: italic;
    border: 1px dashed #ccc;
    border-radius: 4px;
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