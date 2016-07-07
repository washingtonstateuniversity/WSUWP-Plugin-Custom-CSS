/* jshint onevar: false, smarttabs: true */

(function($){
	var WSU_CSS = {
		modes: {
			'default': 'text/css',
			'less': 'text/x-less',
			'sass': 'text/x-scss'
		},
		ajaxSaveCSS: function(){
			var frm = $('#safecssform');
			$.ajax({
				url: frm.attr('action'),
				type:'POST',
				data:frm.serialize()+"&save=Save Stylesheet",
				success: function(data){ 
                    var response = $('<html />').html(data); 
                    jQuery('.post-revisions').html(response.find('.post-revisions').html()); 
                    jQuery('#meta-box-order-nonce').val(response.find('#meta-box-order-nonce').val()); 
                    jQuery('#closedpostboxesnonce').val(response.find('#closedpostboxesnonce').val()); 
                    jQuery('#_wpnonce').val(response.find('#_wpnonce').val()); 
                },
				error: function(data){  }
			});
		},
		init: function() {
			this.$textarea = $( '#safecss' );
			this.editor = window.CodeMirror.fromTextArea( this.$textarea.get(0),{
				mode: this.getMode(),
				extraKeys: {
					"Esc": function(cm) {
					  cm.setOption("fullScreen", !cm.getOption("fullScreen"));
					},
					"Ctrl-S": function(instance) { WSU_CSS.ajaxSaveCSS(); },
					"Cmd-S": function(instance) { WSU_CSS.ajaxSaveCSS(); }
				  },
				lineNumbers: true,
				tabSize: 2,
				indentWithTabs: true,
				lineWrapping: true
			});
			this.setEditorHeight();
            this.addListeners();
		},
		addListeners: function() {
			// nice sizing
			$( window ).on( 'resize', _.bind( _.debounce( this.setEditorHeight, 100 ), this ) );
			// keep textarea synced up
			this.editor.on( 'change', _.bind( function( editor ){
				this.$textarea.val( editor.getValue() );
			}, this ) );
			// change mode
			$( '#preprocessor_choices' ).change( _.bind( function(){
				this.editor.setOption( 'mode', this.getMode() );
			}, this ) );
		},
		setEditorHeight: function() {
			var height = $('html').height() - $( this.editor.getWrapperElement() ).offset().top;
			this.editor.setSize( null, height );
		},
		getMode: function() {
			var mode = $( '#preprocessor_choices' ).val();
			if ( '' === mode || ! this.modes[ mode ] ) {
				mode = 'default';
			}
			return this.modes[ mode ];
		}
	};

	$( document ).ready( _.bind( WSU_CSS.init, WSU_CSS ) );
})(jQuery);
