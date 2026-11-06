/**
 * /bg —— 每日背景图
 *
 * Pages Functions 的路径约定：functions/bg.js 对应 /bg。
 * 具体逻辑在 _lib/bg.js（缓存、回源、校验、版本号都在那里）。
 */

import { handleBgRequest } from './_lib/bg.js';

export const onRequest = (context) => handleBgRequest(context);
