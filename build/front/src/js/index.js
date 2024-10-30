require('es6-promise/auto')
require('whatwg-fetch')

import Uppy from '@uppy/core';
window.Uppy = Uppy

import FileInput from '@uppy/file-input';
import Tus from '@uppy/tus';
import StatusBar from '@uppy/status-bar';
import Informer from '@uppy/informer';
import prettierBytes from '@transloadit/prettier-bytes';

/**
 * Included when youtube_uploader fields are rendered for editing by publishers.
 */
( function( $ ) {
    window.FRUGAN_UFWUFACF = window.FRUGAN_UFWUFACF || {};

	FRUGAN_UFWUFACF.Field = class {
		/**
		 * $field is a jQuery object wrapping field elements in the editor.
		 */
        constructor($field) {
			this.field = $field;
            this.postId = acf.get('post_id');

            //https://stackoverflow.com/a/47647799/3929620
            this.uppyObj = {};
            this.uppyCounter = 0;

            this.init();
        }

		init() {
            if(window[this.field.data('type')].env.debug) {
                console.log(this.field)
            }
    
            var uppyFileInputSelector = this.field[0].querySelector('.UppyFileInput')
    
            if( uppyFileInputSelector ) {
    
                var uppyStatusBarSelector = this.field[0].querySelector('.UppyStatusBar')
                var uppyInformerSelector = this.field[0].querySelector('.UppyInformer')
    
                //https://developer.mozilla.org/it/docs/Learn/HTML/Howto/Uso_attributi_data
                var fieldName = uppyFileInputSelector.getAttribute('data-fieldName')
    
                //https://koukia.ca/top-6-ways-to-search-for-a-string-in-javascript-and-performance-benchmarks-ce3e9b81ad31
                var expr = /acfcloneindex/
    
                if( expr.test(fieldName) ) {
    
                    var ids = this.getParentsDataIds(this.field[0])
    
                    if( ids.length > 0 ) {
    
                        var fieldNameArr = fieldName.split(expr)
    
                        var slicedIds = ids.slice(-(fieldNameArr.length - 1))
    
                        var fieldName = ''
    
                        //https://stackoverflow.com/a/44475397/3929620
                        //https://dmitripavlutin.com/replace-all-string-occurrences-javascript/
                        fieldNameArr.forEach(function(item, i){
                            fieldName += item
    
                            if(this.arrayKeyExists(i, slicedIds)) {
                                fieldName += slicedIds[i]
                            }
                        })
                    }
                }
    
                if( !expr.test(fieldName) ) {
    
                    //https://stackoverflow.com/a/3261380/3929620
                    var max_file_size = uppyFileInputSelector.getAttribute('data-max_file_size')
                    max_file_size = max_file_size.length !== 0 ? parseInt(max_file_size) : null
    
                    var allowed_file_types = uppyFileInputSelector.getAttribute('data-allowed_file_types')
                    allowed_file_types = allowed_file_types.length !== 0 ? JSON.parse(allowed_file_types) : null
    
                    this.uppyObj[this.uppyCounter] = new Uppy({
                        id: 'uppy' + this.uppyCounter,
                        debug: window[this.field.data('type')].env.debug,
                        logger: Uppy.debugLogger,
                        locale: Uppy.locales[window[this.field.data('type')].env.locale],
                        //https://github.com/transloadit/uppy/issues/1575#issuecomment-700584697
                        autoProceed: true,
                        allowMultipleUploads: true,
                        restrictions: {
                            maxFileSize: max_file_size,
                            allowedFileTypes: allowed_file_types,
                            //maxNumberOfFiles: 1,
                        },
                        //https://github.com/transloadit/uppy/issues/1575#issuecomment-500245017
                        //onBeforeFileAdded: (currentFile, files) => this.resetFilesObj(files)
                    })
    
                    this.uppyObj[this.uppyCounter]
                        .use(FileInput, {
                            id: 'FileInput' + this.uppyCounter,
                            target: uppyFileInputSelector,
                            replaceTargetContent: true,
                            //pretty: false,
                        })
                        .use(Tus, {
                            endpoint: window[this.field.data('type')].env.api_path,
                            limit: 1,
                            headers: {
                                'Field-Name': fieldName,
                                //'Upload-Key': fieldName,
                            },
                        })
                        .use(StatusBar, {
                            target: uppyStatusBarSelector,
                            hideUploadButton: true,
                            hideAfterFinish: true,
                        })
                        //https://community.transloadit.com/t/launching-uppy-informer-errors-manually/14907/2
                        .use(Informer, {
                            target: uppyInformerSelector,
                        })
    
                    this.uppyObj[this.uppyCounter]
                        .on('upload-success', (file, response) => {
    
                            //https://developer.mozilla.org/it/docs/Web/HTML/Element/input/file#Note
                            //https://stackoverflow.com/a/8714421/3929620
                            document.querySelector('input[name="' + fieldName + '"]').value = file.name
    
                            var span = document.createElement('span')
                            span.classList.add('dashicons', 'dashicons-trash')
    
                            var a1 = document.createElement('a')
                            //https://developer.mozilla.org/it/docs/Learn/HTML/Howto/Uso_attributi_data
                            a1.dataset.fieldName = fieldName
                            a1.className = 'UppyDelete'
                            a1.href = 'javascript:;'
                            a1.appendChild(span)
    
                            //var a2 = document.createElement('a')
    
                            //FIXME - same uploaded files with different Upload-Key return same uploadURL
                            //https://github.com/ankitpokhrel/tus-php/blob/37e6527b97d0ff44e730064c2c9fddcc0f9f90c5/src/Tus/Server.php#L545
                            //https://github.com/transloadit/uppy/issues/1520
                            //a2.href = response.uploadURL + '/get'
    
                            //a2.target = '_blank'
                            //a2.appendChild(document.createTextNode(file.name))
    
                            var html = a1.outerHTML + ' ' + file.name + ` (${prettierBytes(file.size)})`
    
                            this.field[0].querySelector('.UppyResponse').innerHTML = html
                        })
    
                    //https://github.com/transloadit/uppy/issues/179#issuecomment-312543794
                    this.uppyObj[this.uppyCounter].reset()
    
                    this.uppyCounter++
                }
            }

            //http://youmightnotneedjquery.com/
            //https://gomakethings.com/listening-for-click-events-with-vanilla-javascript/
            //https://medium.com/@florenceliang/javascript-event-delegation-and-event-target-vs-event-currenttarget-c9680c3a46d1
            //https://stackoverflow.com/a/55470424/3929620
            document.addEventListener('click', function(e) {
                for (var target = e.target; target && target != this; target = target.parentNode) {
                    if (target.matches('.UppyDelete')) {
                        e.preventDefault();
                    
                        var fieldName = target.dataset.fieldName
                    
                        if( fieldName ) {
                        
                            //https://koukia.ca/top-6-ways-to-search-for-a-string-in-javascript-and-performance-benchmarks-ce3e9b81ad31
                            var expr = /acfcloneindex/
                        
                            if( expr.test(fieldName) ) {
                            
                                var ids = this.getParentsDataIds(target)
                            
                                if( ids.length > 0 ) {
                                
                                    var fieldNameArr = fieldName.split(expr)
                                
                                    var slicedIds = ids.slice(-(fieldNameArr.length - 1))
                                
                                    var fieldName = ''
                                
                                    //https://stackoverflow.com/a/44475397/3929620
                                    //https://dmitripavlutin.com/replace-all-string-occurrences-javascript/
                                    fieldNameArr.forEach(function(item, i){
                                        fieldName += item
                                    
                                        if(this.arrayKeyExists(i, slicedIds)) {
                                            fieldName += slicedIds[i]
                                        }
                                    })
                                }
                            }
                        
                            if( !expr.test(fieldName) ) {
                            
                                //https://stackoverflow.com/a/8714421/3929620
                                document.querySelector('input[name="' + fieldName + '"]').value = ''
                            }
                        }
                    
                        target.parentNode.innerHTML = ''
                    
                        break;
                    }
                }
            }, false);
        }

        /**
         * https://locutus.io/php/array/array_key_exists/
         * eslint-disable-line camelcase
         * discuss at: https://locutus.io/php/array_key_exists/
         * original by: Kevin van Zonneveld (https://kvz.io)
         * improved by: Felix Geisendoerfer (https://www.debuggable.com/felix)
         * example 1: array_key_exists('kevin', {'kevin': 'van Zonneveld'})
         * returns 1: true
         */
        arrayKeyExists(key, search) {
            if (!search || (search.constructor !== Array && search.constructor !== Object)) {
                return false
            }
            return key in search
        }

        //https://developer.mozilla.org/it/docs/Web/API/Element/closest
        getParentsDataIds(el, parentSelector = 'tr.acf-row', ids = []) {
            //https://stackoverflow.com/a/57449073/3929620
            var parent = el.parentElement.closest(parentSelector)
        
            if( parent && parent !== el ) {
            
                ids.push(parent.dataset.id)
            
                return this.getParentsDataIds(parent, parentSelector, ids)
            }
        
            ids.reverse()
        
            if(window[this.field.data('type')].env.debug) {
                console.log(ids)
            }
        
            return ids
        }

        //https://github.com/transloadit/uppy/issues/1575#issuecomment-500245017
        resetFilesObj(files) {
            Object.keys(files).forEach(function(key){
                delete files[key];
            })
        }
    }

	if( typeof acf.add_action !== 'undefined' ) {
		/**
		 * Run initialize_field when existing fields of this type load,
		 * or when new fields are appended via repeaters or similar.
		 */
		acf.add_action( 'ready_field/type=upload_field_with_uppy_for_acf', function($field) {
            new FRUGAN_UFWUFACF.Field($field);
        });
		acf.add_action( 'append_field/type=upload_field_with_uppy_for_acf', function($field) {
            new FRUGAN_UFWUFACF.Field($field);
        });
	}
} )( jQuery );
