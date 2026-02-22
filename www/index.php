<?php
require_once dirname(__FILE__) . '/header.php';
check_login();
?>
<div class="main-content-wrapper">
    <div class="dashboard-header">
        <h2>
            <i class="fa-solid fa-gauge-high"></i> Trading Overview
        </h2>
        <p>
            반갑습니다, <?php echo h($_SESSION['nickname']); ?>님. 원칙 매매를 응원합니다!
        </p>
    </div>

    <div class="row">
        <div class="col-4">
            <div class="card card-top-accent">
                <div class="card-stats-item">
                    <div>
                        <span>MONTHLY YIELD</span>
                        <div class="stat-value" style="color: var(--up-color);">0.00%</div>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-chart-line"></i></div>
                </div>
            </div>
        </div>
        <div class="col-4">
            <div class="card card-top-down">
                <div class="card-stats-item">
                    <div>
                        <span>WIN RATE</span>
                        <div class="stat-value">0%</div>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-bullseye"></i></div>
                </div>
            </div>
        </div>
        <div class="col-4">
            <div class="card card-top-warning">
                <div class="card-stats-item">
                    <div>
                        <span>TOTAL TRADES</span>
                        <div class="stat-value">0건</div>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-layer-group"></i></div>
                </div>
            </div>
        </div>
    </div><!-- .row -->

    <div class="section-margin-top">
        <div class="row">
            <div class="col-8">
                <div class="card">
                    <div class="section-header">
                        <h3><i class="fa-solid fa-history"></i> 최근 매매 기록</h3>
                        <a href="trade_list.php">더보기</a>
                    </div>
                    <div class="empty-state-card-content">
                        <p>기록된 매매 데이터가 없습니다.</p>
                        <div>
                            <a href="trade_write.php" class="btn btn-primary btn-primary-lg">첫 일지 기록하기</a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div class="card">
                    <div class="section-header">
                        <h3><i class="fa-solid fa-shield-halved"></i> 나의 원칙</h3>
                    </div>
                    <div class="principle-list">
                        <div class="principle-item">
                            <span class="principle-tag principle-tag-stoploss">STOP-LOSS</span>
                            <p class="principle-text">손절가 이탈 시 칼같이 대응</p>
                        </div>
                        <div class="principle-item">
                            <span class="principle-tag principle-tag-volume">VOLUME</span>
                            <p class="principle-text">거래대금 터진 종목만 매매</p>
                        </div>
                        <div class="principle-item">
                            <span class="principle-tag principle-tag-mind">MIND</span>
                            <p class="principle-text">연패 시 무조건 매매 중단</p>
                        </div>
                    </div>
                </div>
            </div>
        </div><!-- .row -->
    </div><!-- .section-margin-top -->
</div><!-- .main-content-wrapper -->
<?php
require_once dirname(__FILE__) . '/footer.php';
?>