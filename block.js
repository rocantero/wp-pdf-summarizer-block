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
            const { pdfUrl, summary, fileName, showSummary, summaryTitle } = attributes;
            const [isUploading, setIsUploading] = useState(false);
            const [customPrompt, setCustomPrompt] = useState('');
            const [isEditingSummary, setIsEditingSummary] = useState(true); // New state for editing summary
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
                    el(PanelBody, { title: __('Block Settings'), key: 'settings' }, [])
                ]),
                
                el('div', { className: 'pdf-summarizer-editor', key: 'content' }, [
                    !pdfUrl && el('div', { className: 'upload-area' }, [
                        el('h3', {}, __('Sumario Ejecutivo')),
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
                        
                        summary && el('div', { className: 'summary-preview' }, [
                            el('h4', {}, summaryTitle),
                            isEditingSummary
                                ? el('textarea', {
                                    value: summary,
                                    onChange: (e) => setAttributes({ summary: e.target.value }),
                                    rows: 20,
                                    style: {
                                        width: '100%',
                                        padding: '10px',
                                        borderRadius: '4px',
                                        border: '1px solid #ddd',
                                        fontSize: '16px',
                                        fontFamily: 'inherit',
                                        lineHeight: '1.5'
                                    }
                                })
                                : el('div', {
                                    style: {
                                        background: 'transparent',
                                        padding: '16px',
                                        whiteSpace: 'pre-wrap'
                                    }
                                }, summary),
                            el('div', { style: { marginTop: '10px' } }, [
                                el(Button, {
                                    isPrimary: true,
                                    style: { fontSize: '18px', padding: '10px 20px', height: '50px' },
                                    onClick: () => setIsEditingSummary(!isEditingSummary)
                                }, isEditingSummary ? __('Guardar Resumen') : __('Editar')),
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
