-- ==========================================
-- INICIALIZAÇÃO DO BANCO POSTGRESQL
-- ==========================================

-- Configurar extensões necessárias
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";

-- Configurar encoding
SET client_encoding = 'UTF8';

-- Log da inicialização
SELECT 'PostgreSQL database initialized successfully for Link Chart' as status;
