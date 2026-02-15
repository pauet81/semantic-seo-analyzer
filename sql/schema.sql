CREATE TABLE IF NOT EXISTS semantic_analysis (
  id INT AUTO_INCREMENT PRIMARY KEY,
  keyword_hash CHAR(64) NOT NULL,
  keywords VARCHAR(255) NOT NULL,
  result_json LONGTEXT NOT NULL,
  tfidf_json LONGTEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_keyword_hash (keyword_hash),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS serp_cache (
  id INT AUTO_INCREMENT PRIMARY KEY,
  keyword VARCHAR(255) NOT NULL,
  keyword_hash CHAR(64) NOT NULL,
  results_json LONGTEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_serp_hash (keyword_hash),
  INDEX idx_serp_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usage_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(64) NOT NULL,
  keywords VARCHAR(255) NOT NULL,
  tokens INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_usage_ip (ip),
  INDEX idx_usage_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
