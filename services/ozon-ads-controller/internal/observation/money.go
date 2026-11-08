// Package observation implements exact, tenant-scoped advertising observations.
package observation

import (
	"errors"
	"math"
	"math/big"
	"regexp"
	"strconv"
	"strings"
	"time"
)

var amountPattern = regexp.MustCompile(`\A(0|[1-9][0-9]*)([.,][0-9]{1,2})?\z`)

// Money always stores minor units; external decimal strings never pass through float.
type Money struct {
	Minor    int64  `json:"amount_minor"`
	Currency string `json:"currency"`
}

func ParseMoney(raw, currency string) (Money, error) {
	if currency != "RUB" || len(raw) > 22 || !amountPattern.MatchString(raw) {
		return Money{}, errors.New("invalid_money")
	}
	parts := strings.Split(strings.ReplaceAll(raw, ",", "."), ".")
	fraction := "00"
	if len(parts) == 2 {
		fraction = parts[1] + strings.Repeat("0", 2-len(parts[1]))
	}
	minor, err := strconv.ParseInt(parts[0]+fraction, 10, 64)
	if err != nil {
		return Money{}, errors.New("money_overflow")
	}
	return Money{Minor: minor, Currency: currency}, nil
}

func (m Money) Add(other Money) (Money, error) {
	if m.Currency != other.Currency || m.Currency != "RUB" || m.Minor < 0 || other.Minor < 0 || m.Minor > math.MaxInt64-other.Minor {
		return Money{}, errors.New("incompatible_money")
	}
	return Money{Minor: m.Minor + other.Minor, Currency: m.Currency}, nil
}

type RateSample struct {
	At    time.Time
	Spend Money
}

// EstimateReserve returns ceil(max observed rate * horizon). It is an estimate,
// not an upper bound of Ozon spend. The caller must separately accept data-age risk.
func EstimateReserve(samples []RateSample, horizon, minimumSpan time.Duration) (Money, error) {
	if len(samples) < 3 || horizon <= 0 || minimumSpan <= 0 || samples[len(samples)-1].At.Sub(samples[0].At) < minimumSpan {
		return Money{}, errors.New("insufficient_history")
	}
	maximum := new(big.Rat)
	for i := 1; i < len(samples); i++ {
		previous, current := samples[i-1], samples[i]
		elapsed := current.At.Sub(previous.At)
		if elapsed <= 0 || current.Spend.Currency != "RUB" || previous.Spend.Currency != "RUB" || previous.Spend.Minor < 0 || current.Spend.Minor < previous.Spend.Minor {
			return Money{}, errors.New("invalid_history")
		}
		rate := new(big.Rat).SetFrac(big.NewInt(current.Spend.Minor-previous.Spend.Minor), big.NewInt(int64(elapsed)))
		if rate.Cmp(maximum) > 0 {
			maximum.Set(rate)
		}
	}
	if maximum.Sign() == 0 {
		return Money{}, errors.New("insufficient_activity")
	}
	amount := new(big.Rat).Mul(maximum, new(big.Rat).SetInt64(int64(horizon)))
	whole, remainder := new(big.Int), new(big.Int)
	whole.QuoRem(amount.Num(), amount.Denom(), remainder)
	if remainder.Sign() > 0 {
		whole.Add(whole, big.NewInt(1))
	}
	if !whole.IsInt64() {
		return Money{}, errors.New("money_overflow")
	}
	return Money{Minor: whole.Int64(), Currency: "RUB"}, nil
}
