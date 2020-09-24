(function( $ ){

	// declaring variables at initialization
	var $convertAllBtn = $('#vo_convert_filter'),
		$output = $('#vo-output'),
		$singleConvertLinks = $('.vo-single-convert'),
		$doactionTopBtn = $('#doaction'),
		$doactionBottomBtn = $('#doaction2'),
		convertQueue = [],
		doingAjax = false;

	// creating hidden Blocks editor
	$('<div />').attr('id', 'vo-editor').attr('style', 'display: none').appendTo('body');
	wp.editPost.initializeEditor('vo-editor');

	// "Bulk Convert All" button handler
	$convertAllBtn.click(function(e){
		e.preventDefault();
		if( ! confirm( voObj.confirmConvertAllMessage ) ) return;
		$convertAllBtn.prop("disabled", true);
		bulkConvertPosts();
	});

	// table "Convert" link handler
	$singleConvertLinks.click(function(e){
		e.preventDefault();

		var postID = $(this).data('json').post;
		convertQueue.push( postID );
		convertPosts();
	});

	$.urlParam = function(name){
		var results = new RegExp('[\?&]' + name + '=([^&#]*)').exec(window.location.href);
		if (results==null) {
			return null;
		}
		return decodeURI(results[1]) || 0;
	}

	// bulk posts converting via ajax
	function bulkConvertPosts( offset = 0, total = -1 ){
		if ( doingAjax ) return;
		doingAjax = true;
		var nonce = $convertAllBtn.data('nonce');
		$output.html( voObj.bulkConvertingMessage );
		$.ajax({
			method: "GET",
			url: voObj.ajaxUrl,
			data: {
				action : "vo_bulk_convert",
				offset : offset,
				total : total,
				vo_post_type : $.urlParam('vo_post_type'),
				vo_category : $.urlParam('vo_category'),
				vo_from_date : $.urlParam('vo_from_date'),
				vo_to_date : $.urlParam('vo_to_date'),
				_wpnonce : nonce
			}
		})
		.done(function( data ){
			doingAjax = false;
			if ( typeof data !== "object" ) {
				$output.html( voObj.serverErrorMessage );
				return;
			}
			if ( data.error ) {
				$output.html( data.message );
				return;
			}
			var convertedData = [];
			var arrayLength = data.postsData.length;
			for (var i = 0; i < arrayLength; i++) {
				var convertedPost = {
					id		: data.postsData[i].id,
					content	: convertToBlocks( data.postsData[i].content )
				};
				convertedData.push( convertedPost );
			}
			bulkSaveConverted( convertedData, data.offset, data.total, data.message );
			return;
		})
		.fail(function(){
			doingAjax = false;
			$output.html( voObj.serverErrorMessage );
		});
	}

	// bulk saving converted posts via ajax
	function bulkSaveConverted( convertedData, offset, total, message ){
		if ( doingAjax ) return;
		doingAjax = true;
		var nonce = $convertAllBtn.data('nonce');
		var jsonData = {
			action : "vo_bulk_convert",
			offset : offset,
			total : total,
			postsData : convertedData,
			_wpnonce : nonce
		};
		$.ajax({
			method: "POST",
			url: voObj.ajaxUrl,
			data: jsonData
		})
		.done(function( data ){
			doingAjax = false;
			if ( typeof data !== "object" ) {
				$output.html( voObj.serverErrorMessage );
				return;
			}
			if ( data.error ) {
				$output.html( data.message );
				return;
			}
			if ( data.offset >= data.total ) {
				$convertAllBtn.prop("disabled", false);
				$output.html( voObj.bulkConvertingSuccessMessage );
				return;
			}
			bulkConvertPosts( offset, total );
			$output.html( message );
			return;
		})
		.fail(function(){
			doingAjax = false;
			$output.html( voObj.serverErrorMessage );
		});
	}

	// single or group posts converting via ajax
	function convertPosts(){
		if( convertQueue.length == 0 ){
			return;
		}
		if ( doingAjax ) return;
		doingAjax = true;
		var postID = convertQueue.shift();
		var $linkObject = $('#vo-single-convert-' + postID);
		$linkObject.hide().after( voObj.convertingSingleMessage );
		$.ajax({
			method: "GET",
			url: voObj.ajaxUrl,
			data: $linkObject.data('json')
		})
		.done(function( data ){
			doingAjax = false;
			if ( typeof data !== "object" ) {
				$linkObject.parent().html( voObj.failedMessage );
				return;
			}
			if ( data.error ) {
				$linkObject.parent().html( voObj.failedMessage );
				return;
			}
			var content = convertToBlocks( data.message );
			saveConverted( content, $linkObject );
			return;
		})
		.fail(function(){
			doingAjax = false;
			$linkObject.parent().html( voObj.failedMessage );
		});
	}

	// posts converting using built in Wordpress library
	function convertToBlocks( content ){
		var blocks = wp.blocks.rawHandler({
			HTML: content
		});
		return wp.blocks.serialize(blocks);
	}

	// single or group saving of converted posts via ajax
	function saveConverted( content, $linkObject ){
		if ( doingAjax ) return;
		doingAjax = true;
		var jsonData = $linkObject.data('json');
		jsonData.content = content;
		$.ajax({
			method: "POST",
			url: voObj.ajaxUrl,
			data: jsonData
		})
		.done(function( data ){
			doingAjax = false;
			$("#vo-convert-checkbox-"+jsonData.post).prop("checked", false);
			$("#vo-convert-checkbox-"+jsonData.post).prop("disabled", true);
			if ( typeof data !== "object" ) {
				$linkObject.parent().html( voObj.failedMessage );
				return;
			}
			if ( data.error ) {
				$linkObject.parent().html( voObj.failedMessage );
				return;
			}
			if (content.includes('<!-- wp:html -->')) {
				$linkObject.parent().html( voObj.convertedSingleHTMLWarning );
			} else {
				$linkObject.parent().html( voObj.convertedSingleMessage );
			}

			convertPosts();
			return;
		})
		.fail(function(){
			doingAjax = false;
			$("#vo-convert-checkbox-"+jsonData.post).prop("checked", false);
			$("#vo-convert-checkbox-"+jsonData.post).prop("disabled", true);
			$linkObject.parent().html( voObj.failedMessage );
		});
	}

	// top action button handler
	$doactionTopBtn.click(function(e){
		e.preventDefault();
		if( $('select[name="action"]').val() === 'bulk-convert' ){
			convertChecked();
		}
	});

	// bottom action button handler
	$doactionBottomBtn.click(function(e){
		e.preventDefault();
		if( $('select[name="action2"]').val() === 'bulk-convert' ){
			convertChecked();
		}
	});

	// add checked posts to converting queue and run converting process
	function convertChecked(){
		$('input[name="bulk-convert[]"]').each(function( index ){
			if( $(this).prop("checked") == true ){
				convertQueue.push( $(this).val() );
			}
		});
		$doactionTopBtn.prop("disabled", true);
		$doactionBottomBtn.prop("disabled", true);
		convertPosts();
	}

})( jQuery );
