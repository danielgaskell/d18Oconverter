library(bayfoxr)
library(ggplot2)
library(dplyr)

foram <- "N. pachyderma"

# get quantiles of training dataset
malevich <- read.csv("malevich_2019_supp_1.csv", header=T)
malevich_d18O_quantiles <- quantile(malevich$d18oc, c(0,0.025,0.5,0.975,1))
malevich_d18Osw_quantiles <- quantile(malevich$d18osw, c(0,0.025,0.5,0.975,1))

# what is the replication error of running bayfox multiple times on the same sample?
errors <- tibble(d18O = 0, d18Osw = 0, id = seq(1, 500, 1))
sst <- predict_seatemp(errors$d18O, d18osw = errors$d18Osw, prior_mean = 17.8, prior_std = 9.0, foram)
sst_quantiles <- quantile(sst, c(0.025,0.5,0.975))
errors$SST_2.5  <- sst_quantiles[,1]
errors$SST_50   <- sst_quantiles[,2]
errors$SST_97.5 <- sst_quantiles[,3]
sd(errors$SST_2.5)
sd(errors$SST_50)
sd(errors$SST_97.5)

# run sample of bayfox estimates
#samples <- 100
#bayfox_SSTs <- tibble(d18O=runif(samples, malevich_d18O_quantiles[1]*2, malevich_d18O_quantiles[5]*2), d18Osw = runif(samples, malevich_d18Osw_quantiles[1]*2, malevich_d18Osw_quantiles[5]*2))
bayfox_SSTs <- malevich %>% select(d18oc, d18osw) %>% rename(d18O = d18oc, d18Osw = d18osw)
for (i in 1:nrow(bayfox_SSTs)) { # rare case where a loop is actually faster than the vectorized version!
  sst <- predict_seatemp(bayfox_SSTs[i,"d18O"], d18osw = bayfox_SSTs[i,"d18Osw"], prior_mean = 17.8, prior_std = 9.0, foram) # mean/std from Malevich et al. (2019)
  sst_quantiles <- quantile(sst, c(0.025,0.5,0.975))
  bayfox_SSTs[i,"SST_2.5"]  <- sst_quantiles[,1]
  bayfox_SSTs[i,"SST_50"]   <- sst_quantiles[,2]
  bayfox_SSTs[i,"SST_97.5"] <- sst_quantiles[,3]
  bayfox_SSTs[i,"SST_sd"]   <- (sst_quantiles[,1] - sst_quantiles[,2]) / qnorm(0.05)
  #ggplot(tibble(x=unlist(sst)), aes(x=x)) + geom_density() + theme_bw()
  #sst <- predict_seatemp(bayfox_SSTs$d18O, d18osw = bayfox_SSTs$d18Osw, prior_mean = 17.8, prior_std = 9.0) # mean/std from Malevich et al. (2019)
  print(i)
}

bayfox_model_2.5  = lm(SST_2.5  ~ d18O, data=bayfox_SSTs %>% mutate(d18O = d18O - d18Osw))
bayfox_model_50   = lm(SST_50   ~ d18O, data=bayfox_SSTs %>% mutate(d18O = d18O - d18Osw))
bayfox_model_97.5 = lm(SST_97.5 ~ d18O, data=bayfox_SSTs %>% mutate(d18O = d18O - d18Osw))
bayfox_sd <- mean(bayfox_SSTs$SST_sd)
summary(bayfox_model_2.5)
summary(bayfox_model_50)
summary(bayfox_model_97.5)

bayfox_SSTs$SST_2.5_predicted  = predict(bayfox_model_2.5,  newdata=bayfox_SSTs)
bayfox_SSTs$SST_50_predicted   = predict(bayfox_model_50,   newdata=bayfox_SSTs)
bayfox_SSTs$SST_97.5_predicted = predict(bayfox_model_97.5, newdata=bayfox_SSTs)

bayfox_SSTs$SST_2.5_residuals   = bayfox_SSTs$SST_2.5  - bayfox_SSTs$SST_2.5_predicted
bayfox_SSTs$SST_50_residuals    = bayfox_SSTs$SST_50   - bayfox_SSTs$SST_50_predicted
bayfox_SSTs$SST_97.5_residuals  = bayfox_SSTs$SST_97.5 - bayfox_SSTs$SST_97.5_predicted

sd(bayfox_SSTs$SST_2.5_residuals)
sd(bayfox_SSTs$SST_50_residuals)
sd(bayfox_SSTs$SST_97.5_residuals)
# notice that the standard deviation of the residuals is indistinguishable to just running the model multiple times on the same sample.

ggplot(bayfox_SSTs) +
  geom_point(aes(x=SST_2.5, y=SST_2.5_predicted), size=0.01) +
  theme_bw()
ggplot(bayfox_SSTs) +
  geom_point(aes(x=SST_50, y=SST_50_predicted), size=0.01) +
  theme_bw()
ggplot(bayfox_SSTs) +
  geom_point(aes(x=SST_97.5, y=SST_97.5_predicted), size=0.01) +
  theme_bw()
