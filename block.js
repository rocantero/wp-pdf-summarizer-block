
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
