<?php
// ussselbot_vk/migrations/002_add_photo_url.sql
// Миграция для добавления поля photo_url в таблицу vk_ad_media

-- Добавление поля photo_url
ALTER TABLE vk_ad_media ADD COLUMN photo_url TEXT NULL COMMENT 'URL фото для перезаливки' AFTER media_id;
