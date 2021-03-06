/**
 * Bitrix Messenger
 * File constants
 *
 * @package bitrix
 * @subpackage im
 * @copyright 2001-2019 Bitrix
 */

const FileStatus = Object.freeze({
	upload: 'upload',
	wait: 'wait',
	done: 'done',
	error: 'error',
});

const FileType = Object.freeze({
	image: 'image',
	video: 'video',
	audio: 'audio',
	file: 'file',
});

export {
	FileStatus,
	FileType
};