/**
 * /api/* 统一入口
 *
 * 文件名的 [[action]] 是 Pages Functions 的捕获语法：
 *   /api/login          → params.action = ['login']
 *   /api/get_settings   → params.action = ['get_settings']
 *
 * 所有路由分发逻辑在 ../_lib/handlers.js。
 */

import { handleApi } from '../_lib/handlers.js';

export const onRequest = (context) => handleApi(context);
